<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Service;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\WebSweep;
use Illuminate\Support\LazyCollection;
use Illuminate\Database\Eloquent\Builder;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs\DatasetItem;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Models\WebSweepRun;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs\WebhookPayload;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Models\WebSweepCrawlItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class WebSweepService
{
    public static array $observedEvents = [
        'ACTOR.RUN.SUCCEEDED',
        'ACTOR.RUN.ABORTED',
        'ACTOR.RUN.TIMED_OUT',
        'ACTOR.RUN.FAILED',
    ];

    public static function validateActorId(?string $actorId = null): ?string
    {
        if (empty($actorId)) {
            return null;
        }

        return array_key_exists($actorId, self::activeActors()) ? $actorId : null;
    }

    public static function activeActors(): array
    {
        return [
            config('websweep.default_actor_id') => 'Fastest: no JavaScript support',
            config('websweep.second_actor_id')  => 'Slower: can validate Captcha',
            config('websweep.third_actor_id')   => 'Slowest: better support, best coverage',
        ];
    }

    public static function findApifyRun(array $filters = []): ?WebSweepRun
    {
        if (empty($filters)) {
            return null;
        }

        $startUrl = data_get($filters, 'startUrl');
        $serviceId = data_get($filters, 'serviceId');
        $datasetId = data_get($filters, 'datasetId');
        $actorRunId = data_get($filters, 'actorRunId');

        $webhookPayload = WebhookPayload::fromArray($filters);

        // Accept either eventData OR resource for actor events
        $forAnalytics = (filled($webhookPayload->eventData) || filled($webhookPayload->resource));
        $forStatusUpdate = (filled($startUrl) && filled($serviceId));

        $run = null;

        if ($forAnalytics) {
            $actorId = $webhookPayload->eventData?->actorId ?? $webhookPayload->resource?->actId;
            $runId = $webhookPayload->eventData?->actorRunId ?? $webhookPayload->resource?->id;

            if (empty($actorId) || empty($runId)) {
                return null;
            }

            $run = WebSweepRun::query()
                ->where('run_id', $runId)
                ->whereIn('actor_id', [$actorId, config('websweep.default_actor_id'), config('websweep.second_actor_id')])
                ->latest('dataset_id')
                ->first();
        }

        if ($forStatusUpdate) {
            $run = WebSweepRun::query()
                ->where('service_id', $serviceId)
                ->where('start_url', $startUrl)
                ->where('dataset_id', $datasetId)
                ->when(filled($actorRunId), fn (Builder $q) => $q
                    ->where('run_id', $actorRunId)
                )
                ->latest('dataset_id')
                ->first();

            if (empty($run)) {
                $run = WebSweepRun::query()
                    ->where('service_id', $serviceId)
                    ->where('start_url', $startUrl)
                    ->when(filled($actorRunId), fn (Builder $q) => $q
                        ->where('run_id', $actorRunId)
                    )
                    ->whereNull('dataset_id')
                    ->first();
            }
        }

        return empty($run) ? null : $run;
    }

    public static function storeDatasetItems(WebSweepRun $run, ?Collection $datasetItems = null): void
    {
        $package = app(WebSweep::class);

        if (! isset($datasetItems) || $datasetItems?->isEmpty()) {
            if (empty($run->dataset_id)) {
                return;
            }

            $datasetItems = LazyCollection::make(fn () => $package->getDatasetItems($run->dataset_id));
        }

        /** @var Collection<DatasetItem> $chunk */
        $datasetItems->chunk(100)->each(function (Collection|LazyCollection $batch) use ($package, $run) {
            $filteredData = [];

            $batch->filter(fn (DatasetItem $item) => ! empty($item->url))
                ->unique('url')
                ->each(function (DatasetItem $item) use ($package, &$filteredData, $run) {
                    $lookup = self::getLookupHash($item->url);

                    if ($lookup > 0 && Arr::first($filteredData, fn (array $fileData) => $lookup === data_get($fileData, 'lookup_hash'))) {
                        return;
                    }

                    if (WebSweepCrawlItem::query()
                        ->where('service_id', $run->service_id)
                        ->where('url', $item->url)
                        ->when(filled($lookup), fn (Builder $q) => $q->where('lookup_hash', $lookup))
                        ->exists()
                    ) {
                        return;
                    }

                    $extension = $package->getFileExtensionFromFileName($item->fileId) // usually a filename
                        ?? $package->getFileExtensionFromRemoteUrl($item->url); // HEAD request of URL

                    if (str($item->extension)->length() >= 1 && str($item->extension)->length() <= 4) {
                        $extension = $item->extension;
                    }

                    $filetype = $package->getFileTypeFromExtension($extension)
                        ?? $package->getMimeTypeOrExtension($extension)
                        ?? $item->type;

                    $filteredData[] = [
                        'id'             => str()->uuid()->toString(),
                        'apify_run_id'   => $run->id,
                        'service_id'     => $run->service_id,
                        'lookup_hash'    => $lookup,
                        'url'            => $item->url,
                        'from_url'       => $item->fromUrl,
                        'text'           => $item->text,
                        'file_id'        => $item->fileId,
                        'file_name'      => $item->name,
                        'file_extension' => $extension,
                        'type'           => $filetype,
                        'should_import'  => true,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ];
                });

            try {
                DB::table('apify_crawl_items')->upsert(
                    values: $filteredData,
                    uniqueBy: ['service_id', 'url'],
                    update: ['file_name', 'file_extension', 'from_url', 'text']
                );
            } catch (Exception $e) {
                logger()?->error("Error Storing Dataset Items: {$e->getMessage()}:", $e->getTrace());
            }

            unset($filteredData);
        });
    }

    public static function getLookupHash(string $url, ?int $length = 8): int
    {
        $hash = sha1($url);
        $lookup = substr($hash, 0, $length);

        return (int) hexdec($lookup);
    }

    public static function fetchCrawledItem(?string $itemId = null, bool $hardFail = false): ?WebSweepCrawlItem
    {
        if (empty($itemId) || ! str($itemId)->isUuid()) {
            return null;
        }

        try {
            return WebSweepCrawlItem::query()->findOrFail($itemId);
        } catch (ModelNotFoundException $e) {
            // silently ignore files without crawled item
            throw_if($hardFail, $e);
        } catch (Exception $e) {
            logger()->error($e->getMessage(), $e->getTrace());
            throw_if($hardFail, $e);
        }

        return null;
    }
}
