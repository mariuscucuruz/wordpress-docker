<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep;

use Exception;
use Generator;
use Throwable;
use RuntimeException;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\ConnectionException;
use Symfony\Component\HttpFoundation\Response;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Support\CacheKey;
use MariusCucuruz\DAMImporter\Models\ImportGroup;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use MariusCucuruz\DAMImporter\Enums\ImportGroupStatus;
use MariusCucuruz\DAMImporter\DTOs\IntegrationDefinition;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs\Run;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs\DatasetItem;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Models\WebSweepRun;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs\ActorRunInputs;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Traits\HasDownloads;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Enums\WebSweepRunStatus;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Jobs\StoreRunDatasetJob;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Service\WebSweepService;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Jobs\PollApifyDatasetJob;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Jobs\SyncCrawledItemsJob;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Models\WebSweepCrawlItem;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Traits\HasStandardMethods;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use Illuminate\Database\Eloquent\Collection as DbCollection;
use MariusCucuruz\DAMImporter\SourcePackageManager;

class WebSweep extends SourcePackageManager implements CanPaginate
{
    use HasDownloads;
    use HasStandardMethods;
    use Loggable;

    public string $path = __DIR__;

    public ?string $apiToken = null;

    public ?string $defaultActorId = null;

    public ?string $secondActorId = null;

    public ?string $thirdActorId = null;

    public ?string $baseUrl = null;

    public ?string $webhookUri = null;

    public static function definition(): IntegrationDefinition
    {
        return IntegrationDefinition::make(
            'websweep',
            'WebSweep',
            WebSweepServiceProvider::class,
            [],
        );
    }

    public function initialize(): static
    {
        $configs = config('websweep', []);
        throw_if(empty($configs), InvalidSettingValue::class, 'WebSweep configuration is missing.');

        $this->apiToken = data_get($configs, 'api_token');
        $this->defaultActorId = data_get($configs, 'default_actor_id');
        $this->secondActorId = data_get($configs, 'second_actor_id');
        $this->thirdActorId = data_get($configs, 'third_actor_id');
        $this->baseUrl = data_get($configs, 'base_url');
        $this->webhookUri = url(data_get($configs, 'webhook_uri', ''), [], true);

        throw_unless($this->defaultActorId, InvalidSettingValue::class, 'Actor ID is required');
        throw_unless($this->apiToken, InvalidSettingValue::class, 'API Token is required');
        throw_unless($this->webhookUri, InvalidSettingValue::class, 'Webhook URL is required');
        throw_unless($this->baseUrl, InvalidSettingValue::class, 'API Base URL is required');

        return $this;
    }

    public function http(): PendingRequest
    {
        if (empty($this->baseUrl) || empty($this->apiToken)) {
            $this->initialize();
        }

        return Http::timeout(60)
            ->connectTimeout(15)
            ->maxRedirects(10)
            ->baseUrl($this->baseUrl)
            ->withToken($this->apiToken)
            ->asJson()
            ->acceptJson()
            ->throw();
    }

    public function redirectToAuthUrl(?string $email = null)
    {
        $serviceSettings = $this->getSettings();

        $startUrl = data_get($serviceSettings, 'WEBSWEEP_START_URL');
        $startUrl = str_starts_with($startUrl, 'http') ? $startUrl : "https://{$startUrl}";

        try {
            [$validStartUrl] = $this->validateStartUrl($startUrl);
        } catch (Throwable $e) {
            return $this->redirectWithError("{$startUrl} is invalid: {$e->getMessage()}.", $e->getTrace());
        }

        $user = $this->getUser($email)?->toArray();
        throw_unless(! empty($user), InvalidSettingValue::make('email'));

        $service = Service::query()
            ->updateOrCreate([
                'name'              => 'websweep',
                'interface_type'    => self::class,
                'remote_service_id' => $startUrl,
                'user_id'           => data_get($user, 'user_id'),
                'team_id'           => data_get($user, 'team_id'),
            ], array_filter([
                ...$this->serviceProperties($this->service->toArray())->toArray(),
                'email'   => data_get($user, 'email'),
                'options' => $serviceSettings,
            ]));

        if (empty($service)) {
            return $this->redirectWithError('Cannot initiate sweep without a service.', compact('settings', 'user', 'validStartUrl', 'startUrl'));
        }

        $settings->whereNull('service_id')
            ->each(fn (array $setting) => $setting->update(['service_id' => $service->id]));

        $service->setMetaExtra(['settings' => $serviceSettings]);

        $actorRun = $this->runActor($validStartUrl, $service, data_get($serviceSettings, 'WEBSWEEP_ACTOR'));

        if (empty($actorRun)) {
            return $this->redirectWithError("Could not retrieve actor data for {$validStartUrl}.", $actorRun?->toArray());
        }

        dispatch(new PollApifyDatasetJob($actorRun->id, true))
            ->afterResponse()
            ->delay(now()->addMinutes(5));

        $service->setMetaExtra([
            'run'      => $actorRun->toArray(),
            'settings' => $serviceSettings,
        ]);

        flash('Started successfully, crawling is in progress.');

        return $this->redirectToServiceUrl($service->id);
    }

    public function paginate(?array $request = []): void
    {
        $crawledItems = WebSweepCrawlItem::query()
            ->where('service_id', $this->service->id)
            ->where('should_import', true)
            ->whereNotNull('url');

        if ($crawledItems->count() === 0 && $startUrl = data_get($this->service?->options, 'startUrl')) {
            $this->log('No new crawl items found, re-dispatching dataset store for existing runs...');

            WebSweepRun::query()
                ->where('service_id', $this->service->id)
                ->where('start_url', $startUrl)
                ->whereNotNull('dataset_id')
                ->latest('id')
                ->eachById(fn (WebSweepRun $run) => dispatch(new StoreRunDatasetJob($run->id)));

            return;
        }
    }

    public function runActor(string $startUrl, ?Service $service = null, ?string $actorId = null): ?WebSweepRun
    {
        if (empty($this->defaultActorId) && empty($this->webhookUri)) {
            $this->initialize();
        }

        $startUrl = str_starts_with($startUrl, 'http') ? $startUrl : "https://{$startUrl}";

        // Idempotency: if a recent/active run exists for this service and startUrl, return it instead of creating a new one
        $existing = WebSweepRun::query()
            ->where('service_id', $service->id)
            ->where('start_url', $startUrl)
            ->whereIn('status', WebSweepRunStatus::runningCases()->toArray())
            ->where(fn (Builder $query) => $query
                ->whereNull('finished_at')
                ->orWhere('finished_at', '>', now()->subMinutes(10))
            )
            ->latest('created_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        $settings = $service->getMetaExtra('settings');

        $actorId ??= $this->decideWhichAgentToUse($startUrl, data_get($settings, 'WEBSWEEP_ACTOR'));
        throw_unless($actorId, RuntimeException::class, 'Crawl Agent / Actor is undecided!');

        $context = ActorRunInputs::make(
            serviceId: $service->id,
            startUrl: $startUrl,
            email: auth()->user()?->email ?? $this->service?->user?->email,
            timeout: data_get($settings, 'WEBSWEEP_TIMEOUT'),
            maxDepth: data_get($settings, 'WEBSWEEP_MAX_DEPTH'),
            maxRedirects: data_get($settings, 'WEBSWEEP_MAX_REDIRECTS'),
            requestsPerCrawl: data_get($settings, 'WEBSWEEP_MAX_REQUESTS'),
        );

        $query = [
            'timeout'  => (int) config('websweep.timeout', (30 * 60)),
            'webhooks' => base64_encode(json_encode([
                [
                    'eventTypes' => WebSweepService::$observedEvents,
                    'requestUrl' => $this->webhookUri,
                ],
            ])),
        ];

        try {
            $response = $this->http()->post(
                "{$this->baseUrl}/v2/acts/{$actorId}/runs?" . http_build_query($query),
                ['json' => $context->toArray()]
            )->json();

            $actorRun = Run::fromArray($response);
        } catch (Exception $exception) {
            $this->log("Failed to start actor {$actorId}: {$exception->getMessage()}", 'error', null, [
                'request'  => $context->toArray(),
                'response' => $response ?? null,
                'stack'    => $exception->getTrace(),
            ]);

            return null;
        }

        if (empty($actorRun->data)) {
            $this->log("Could not retrieve actor data for {$actorId}.", 'error', null, $context->toArray());

            return null;
        }

        return WebSweepRun::create([
            'id'               => str()->uuid(),
            'service_id'       => $service->id,
            'run_id'           => $actorRun->data->id,
            'actor_id'         => $actorId,
            'dataset_id'       => null,
            'request_queue_id' => null,
            'start_url'        => $startUrl,
            'status'           => $actorRun->data->status,
            'total_checks'     => 0,
            'stats'            => $actorRun->data->stats?->toArray(),
            'started_at'       => $actorRun->data->startedAt,
            'finished_at'      => null,
            'last_check_at'    => now()->subSeconds(15),
        ]);
    }

    public function getDatasetItems(string $datasetId): ?Generator
    {
        $batchSize = (int) (config('websweep.http.page_size') ?? 100);
        $maxRetries = (int) (config('websweep.http.max_retries') ?? 5);
        $initialBackoffMs = (int) (config('websweep.http.initial_backoff_ms') ?? 500);
        $maxBackoffMs = (int) (config('websweep.http.max_backoff_ms') ?? 8000);
        $requestTimeout = (int) (config('websweep.http.request_timeout') ?? 60);
        $connectTimeout = (int) (config('websweep.http.connect_timeout') ?? 15);

        $batchSize = $batchSize > 0 ? $batchSize : 100;

        $offset = 0;

        do {
            $items = [];

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    $response = $this->http()
                        ->timeout($requestTimeout)
                        ->connectTimeout($connectTimeout)
                        ->get("{$this->baseUrl}/v2/datasets/{$datasetId}/items?" . http_build_query([
                            'format' => 'json',
                            'clean'  => 'true',
                            'limit'  => $batchSize,
                            'offset' => $offset,
                        ]));

                    if ($response->successful()) {
                        $items = $response->json();

                        foreach ($items as $item) {
                            // Normalize snake_case keys to our DTO shape
                            if (is_array($item)) {
                                if (isset($item['from_url']) && ! isset($item['fromUrl'])) {
                                    $item['fromUrl'] = $item['from_url'];
                                }

                                if (isset($item['file_id']) && ! isset($item['fileId'])) {
                                    $item['fileId'] = $item['file_id'];
                                }
                            }

                            if (data_get($item, 'url')) {
                                yield DatasetItem::fromArray($item);
                            }
                        }

                        $offset += $batchSize;

                        break; // success: exit attempts, get next batch
                    }

                    // Retry on server errors or 429
                    if ($response->serverError() || $response->status() === 429) {
                        if ($attempt < $maxRetries) {
                            $backoff = min($maxBackoffMs, $initialBackoffMs * (2 ** ($attempt - 1)));
                            usleep((($backoff + random_int(0, 250)) * 1000));

                            continue;
                        }

                        $this->log("({$offset}) Failed to load dataset from {$datasetId}: HTTP {$response->status()}:", 'error');

                        return null;
                    }

                    // Client errors are not retriable; log and abort the whole fetch
                    $this->log("({$offset}) Failed to load dataset from {$datasetId}: HTTP {$response->status()}:", 'error');

                    return null;
                } catch (ConnectionException $e) {
                    // Transient network error; retry with backoff if we still have attempts left
                    if ($attempt < $maxRetries) {
                        $backoff = min($maxBackoffMs, $initialBackoffMs * (2 ** ($attempt - 1)));
                        usleep((($backoff + random_int(0, 250)) * 1000));

                        continue;
                    }

                    $this->log("({$offset}) Failed to load dataset from {$datasetId}: {$e->getMessage()}", 'error');

                    return null;
                } catch (Exception $exception) {
                    // Transient errors (e.g., partial transfers) are retried until max retries reached
                    if ($attempt < $maxRetries) {
                        $backoff = min($maxBackoffMs, $initialBackoffMs * (2 ** ($attempt - 1)));
                        usleep((($backoff + random_int(0, 250)) * 1000));

                        continue;
                    }

                    // Exhausted retries or non-transient
                    $this->log("({$offset}) Failed to load dataset from {$datasetId}: {$exception->getMessage()}", 'error', null, $exception->getTrace());

                    return null;
                }
            }

            // simply skip pages (i.e. batch) that are not available
            $offset += $batchSize;
        } while (count($items) === $batchSize);
    }

    public function getRun(string $runId): ?Run
    {
        try {
            $resp = $this->http()->get("{$this->baseUrl}/v2/actor-runs/{$runId}");
        } catch (Exception) {
            return null;
        }

        if ($resp->serverError()) {
            return null;
        }

        if ($resp->clientError()) {
            throw new RuntimeException("Cannot find run: {$runId}.");
        }

        if ($error = data_get($resp->json(), 'error')) {
            throw new RuntimeException('Error ' . data_get($error, 'type') . ': ' . data_get($error, 'message'));
        }

        $runData = data_get($resp->json(), 'data') ?? $resp->json();

        return Run::make($runData);
    }

    public function findDatasetIdByName(string $name): ?string
    {
        $resp = $this->http()->get("{$this->baseUrl}/v2/datasets?desc=1&limit=1000")->json();

        $datasets = data_get($resp, 'data.items', $resp);

        foreach ($datasets as $ds) {
            if (data_get($ds, 'name') === $name) {
                return data_get($ds, 'id');
            }
        }

        return null;
    }

    public function findRequestQueueIdByName(string $name): ?string
    {
        $resp = $this->http()
            ->get("{$this->baseUrl}/v2/request-queues?desc=1&limit=1000")
            ->json();

        $queues = data_get($resp, 'data.items', $resp);

        foreach ($queues as $q) {
            if (data_get($q, 'name') === $name) {
                return data_get($q, 'id');
            }
        }

        return null;
    }

    public function validateStartUrl(string $startUrl): ?array
    {
        throw_if(empty($startUrl) || strlen($startUrl) < 3, 'Start URL is required');

        $urlParsed = parse_url($startUrl);
        throw_if(empty($urlParsed), 'Invalid start URL');

        $urlParts = explode('/', str(data_get($urlParsed, 'path', $startUrl))->remove('www.')->toString());
        $crawlDomain = data_get($urlParsed, 'host') ?? $urlParts[0];
        throw_if(! $crawlDomain || strlen($crawlDomain) < 4, 'Invalid domain in startURL'); // x.io

        if (! str_starts_with($startUrl, 'http')) {
            $startUrl = "https://{$startUrl}";
        }

        return [$startUrl, $crawlDomain];
    }

    public function isUrlReachable(string $startUrl): bool
    {
        $cacheKey = CacheKey::build('websweep_url_check_', sha1($startUrl));

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($startUrl): bool {
            return (bool) rescue(
                callback: fn () => Http::timeout(10)
                    ->connectTimeout(5)
                    ->withHeader('Referer', parse_url($startUrl, PHP_URL_HOST))
                    ->get($startUrl)
                    ->throw()
                    ->getStatusCode() < Response::HTTP_BAD_REQUEST,
                rescue: function (Throwable $e) use ($startUrl) {
                    $this->log("WebSweep cannot reach {$startUrl}: {$e->getMessage()}", 'error', null, $e->getTrace());

                    return false;
                });
        });
    }

    /**
     * (Crawl) Agent === (Apify) Actor
     * Rankings:
     * 1. the default crawler is the fastest but has no support for JS;
     * 2. the second actor is slower but supports JS and can bypass Captcha challenges;
     * 3. the 3rd (TBC) is slowest but supports richer websites and more complex Captchas;
     * 4. (coming soon) vendor-specific crawler with a well-defined scope;
     */
    private function decideWhichAgentToUse(string $startUrl, ?string $actorId = null): ?string
    {
        if (! empty($actorId) && WebSweepService::validateActorId($actorId)) {
            return $actorId;
        }

        $actorId = $this->defaultActorId;

        try {
            [$crawlStartUrl, $crawlDomain] = $this->validateStartUrl($startUrl);

            // switch to secondActor only if we have issues reaching $startUrl
            if (! $this->isUrlReachable($crawlStartUrl) || ! $this->isUrlReachable($crawlDomain)) {
                $this->log("Domain is not reachable [changing actor]: {$crawlDomain}", 'warn');

                $actorId = $this->secondActorId;
            }
        } catch (Exception $e) {
            $this->log("{$startUrl} is invalid: {$e->getMessage()}.", 'warn', null, $e->getTrace());

            $actorId = $this->thirdActorId ?? $this->secondActorId;
        }

        return WebSweepService::validateActorId($actorId);
    }
}
