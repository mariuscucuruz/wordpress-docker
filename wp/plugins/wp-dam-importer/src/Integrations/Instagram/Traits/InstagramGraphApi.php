<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Instagram\Traits;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Enums\MetaType;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotQuery;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use MariusCucuruz\DAMImporter\Integrations\Instagram\Enums\InstagramServiceType;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;

trait InstagramGraphApi
{
    public function initializeGraphApi(): void
    {
        $settings = $this->getSettings();

        if (! isset($settings['clientId'], $settings['clientSecret'])) {
            $this->log('Instagram settings are required', 'error');

            return;
        }

        $this->clientId = $settings['clientId'];
        $this->clientSecret = $settings['clientSecret'];
        $this->redirectUri = $settings['redirectUri'];
        $this->configId = $settings['configId'];
    }

    public function getSettingsGraphApi(): array
    {
        $settings = parent::getSettings(array_keys(config('instagram.settings.INSTAGRAM_GRAPH_SETTINGS', [])));

        $clientId = $settings['INSTAGRAM_GRAPH_CLIENT_ID'] ?? config('instagramGraph.client_id');
        $clientSecret = $settings['INSTAGRAM_GRAPH_SECRET'] ?? config('instagramGraph.client_secret');
        $configId = $settings['INSTAGRAM_GRAPH_CONFIG_ID'] ?? config('instagramGraph.config_id');

        $redirectUri = config('instagramGraph.redirect_uri');

        return compact('clientId', 'clientSecret', 'redirectUri', 'configId');
    }

    public function redirectToAuthUrlGraphApi(?Collection $settings = null, string $email = ''): void
    {
        try {
            $url = config('instagramGraph.oauth_base_url') . '/dialog/oauth';

            throw_unless(
                $url && $this->clientId && $this->redirectUri && $this->configId,
                CouldNotInitializePackage::class,
                'Instagram graph settings are required!'
            );

            $state = $this->generateRedirectOauthState([
                'meta' => ['account_type' => InstagramServiceType::BUSINESS->value],
            ]);

            $queryParams = [
                'client_id'    => $this->clientId,
                'redirect_uri' => $this->redirectUri,
                'config_id'    => $this->configId,
                'auth_type'    => 'reauthenticate',
                'state'        => $state,
            ];

            $queryString = http_build_query($queryParams);
            $requestUrl = "{$url}?{$queryString}";
            $this->redirectTo($requestUrl);
        } catch (CouldNotInitializePackage|CouldNotQuery|Throwable|Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function getUserGraphApi(): ?UserDTO
    {
        try {
            $response = Http::timeout(config('queue.timeout'))->get(config('instagramGraph.query_base_url') . '/me', [
                'access_token' => $this->accessToken,
                'Accept'       => 'application/json',
            ])->throw();

            $body = $response->collect();
            $userId = data_get($body, 'id');

            throw_unless($userId, CouldNotQuery::class, 'No user id found in the response');

            $response = Http::timeout(config('queue.timeout'))->get(config('instagramGraph.query_base_url') . '/' . $userId, [
                'fields'       => config('instagramGraph.fields.user'),
                'access_token' => $this->accessToken,
            ])->throw();

            $body = $response->collect();
            $name = data_get($body, 'name');

            throw_unless($name, CouldNotQuery::class, 'Name not found in the response');

            return new UserDTO([
                'email'   => $name,
                'photo'   => $this->uploadThumbnail(null, data_get($body, 'picture.data.url')),
                'name'    => $name,
                'user_id' => $userId,
            ]);
        } catch (Exception|Throwable $e) {
            $this->log($e->getMessage(), 'error');

            return new UserDTO;
        }
    }

    /**
     * @throws CouldNotGetToken|Throwable
     */
    public function getTokensGraphApi(array $tokens = []): TokenDTO
    {
        try {
            $response = Http::timeout(config('queue.timeout'))->get(config('instagramGraph.query_base_url') . '/oauth/access_token', [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri'  => $this->redirectUri,
                'code'          => request('code'),
            ])->throw();

            $body = $response->collect();

            return new TokenDTO($this->storeToken($body));
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                $this->service?->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    /**
     * @throws Throwable
     */
    public function storeTokenGraphApi($body): array
    {
        $accessToken = data_get($body, 'access_token');
        throw_unless($accessToken, CouldNotGetToken::class, 'Invalid token response.');
        $this->accessToken = $accessToken;

        $expiresInSeconds = data_get($body, 'expires_in');
        $expires = $expiresInSeconds ? now()->addseconds($expiresInSeconds)->getTimestamp() : null;

        $longLiveTokenData = $this->exchangeAccessTokenForLongLiveAccessTokenGraphApi($this->accessToken);
        $this->accessToken = data_get($longLiveTokenData, 'access_token', $this->accessToken);
        $expires = data_get($longLiveTokenData, 'expires', $expires);

        return [
            'access_token'  => $this->accessToken,
            'token_type'    => data_get($body, 'token_type'),
            'expires'       => $expires,
            'token'         => null,
            'refresh_token' => null, // Graph API does not use refresh tokens
            'account_type'  => InstagramServiceType::BUSINESS->value,
        ];
    }

    public function exchangeAccessTokenForLongLiveAccessTokenGraphApi($token): ?array
    {
        try {
            $response = Http::timeout(config('queue.timeout'))->get(config('instagramGraph.query_base_url') . '/oauth/access_token', [
                'client_id'         => $this->clientId,
                'client_secret'     => $this->clientSecret,
                'grant_type'        => 'fb_exchange_token',
                'fb_exchange_token' => $token,
            ])->throw();

            $body = $response->collect();
            $accessToken = data_get($body, 'access_token', false);

            throw_unless($accessToken, CouldNotGetToken::class, 'Access token not found in the response');

            $expiresInSeconds = data_get($body, 'expires_in');
            $expires = $expiresInSeconds ? now()->addseconds($expiresInSeconds)->getTimestamp() : null;

            return [
                'access_token' => $accessToken,
                'expires'      => $expires,
            ];
        } catch (CouldNotGetToken|Throwable|Exception $e) {
            $this->checkAndHandleServiceAuthorisation();
            $this->log($e->getMessage(), 'error');

            return null;
        }
    }

    public function testSettingsGraphApi(Collection $settings): bool
    {
        if ($settings->isEmpty()) {
            $clientId = $this->clientId ?? '';
            $clientSecret = $this->clientSecret ?? '';
            $configId = $this->configId ?? '';

            $settings = collect(compact('clientId', 'clientSecret', 'configId'));
        } else {
            $clientId = $settings->firstWhere('name', 'INSTAGRAM_GRAPH_CLIENT_ID')?->payload ?? '';
            $clientSecret = $settings->firstWhere('name', 'INSTAGRAM_GRAPH_SECRET')?->payload ?? '';
            $configId = $settings->firstWhere('name', 'INSTAGRAM_GRAPH_CONFIG_ID')?->payload ?? '';
        }

        abort_if($settings->isEmpty(), 400, 'Instagram settings are required');
        abort_if(count(config('instagram.settings')) !== $settings->count(), 406, 'All Settings must be present');

        // Updated pattern to match client IDs with 10 to 15 digits at the start
        $clientIdPattern = '/^[a-zA-Z0-9]{15,}$/'; // max: 32?
        $clientSecretPattern = '/^[a-zA-Z0-9]{32,}$/'; // max: 64?
        $configIdPattern = '/^[a-zA-Z0-9]{15,}$/';

        abort_if(! preg_match($clientIdPattern, $clientId), 406, 'Looks like your client ID format is invalid');
        abort_if(
            ! preg_match($clientSecretPattern, $clientSecret),
            406,
            'Looks like your client secret format is invalid'
        );
        abort_if(! preg_match($configIdPattern, $configId), 406, 'Looks like your config ID format is invalid');

        return true;
    }

    /**
     * @throws Throwable
     */
    public function paginateGraphApi(array $request = []): void
    {
        $folders = data_get($request, 'metadata') ?? [];

        if (empty($folders)) {
            $this->getFilesGraphApi();

            return;
        }

        array_walk($folders, function ($folder) {
            $id = data_get($folder, 'folder_id');
            $startDateInput = data_get($folder, 'start_time');
            $endDateInput = data_get($folder, 'end_time');

            if (! empty($startDateInput) && ! empty($endDateInput)) {
                $this->log("Invalid date range for folder ID: {$id}", 'error');

                continue;
            }

            if (! empty($id)) {
                $this->getFilesGraphApi($id);
            }
        });
    }

    /**
     * @throws Throwable
     */
    public function getFilesGraphApi(string $id = 'all'): void
    {
        if ($id === 'all') {
            $nextUrl = null;

            do {
                $facebookPageData = $this->getPagesGraphApi($nextUrl);

                foreach (data_get($facebookPageData, 'data', []) as $facebookPage) {
                    if ($instagramPageId = data_get($facebookPage, 'instagram_business_account.id')) {
                        $endpoint = Path::join(config('instagramGraph.query_base_url'), $instagramPageId, 'media');
                        $this->getFilesAndDispatchGraphApi($endpoint, $instagramPageId);
                    }
                }

                $nextUrl = data_get($facebookPageData, 'paging.next');
            } while (filled($nextUrl));

            return;
        }

        $endpoint = Path::join(config('instagramGraph.query_base_url'), $id, 'media');
        $this->getFilesAndDispatchGraphApi($endpoint, $id);
    }

    public function getFilesAndDispatchGraphApi(string $endpoint, string $pageId): void
    {
        $i = 0;
        $defaultQueryParams = [
            'fields'       => config('instagramGraph.fields.media'),
            'limit'        => config('instagramGraph.per_page'),
            'access_token' => $this->service->access_token,
            'since'        => $this->syncFilterDateRange?->start->timestamp,
            'until'        => $this->syncFilterDateRange?->end->timestamp,
        ];

        $endpoint = $endpoint . '?' . http_build_query($defaultQueryParams);

        while (true) {
            try {
                $response = Http::timeout(config('queue.timeout'))->get($endpoint);

                if ($response->failed()) {
                    $this->checkAndHandleServiceAuthorisation();
                    $this->log($response->body(), 'error');

                    break;
                }

                $data = data_get($response->collect(), 'data', []);
                $files = [];

                foreach ($data as $item) {
                    if ($this->isDateSyncFilter && ! $this->isWithinDatePeriod(data_get($item, 'timestamp'))) {
                        continue;
                    }

                    data_set($item, 'page_id', $pageId);
                    $files[] = $item;
                    $children = $this->getItemChildren($item);
                    $files = [...$files, ...$children];
                }

                if (filled($files)) {
                    $this->dispatch($files, 'Page ' . $i);
                    $i++;
                }

                $endpoint = data_get($response->collect(), 'paging.next', false);

                if (! $endpoint) {
                    break;
                }
            } catch (Exception $e) {
                $this->log($e->getMessage(), 'error');
                $this->checkAndHandleServiceAuthorisation();

                break;
            }
        }
    }

    public function getPagesGraphApi(?string $nextUrl = null): array
    {
        $query = $nextUrl ?? config('instagramGraph.query_base_url') . '/me/accounts';
        $options = $nextUrl ? null : [
            'access_token' => $this->service->access_token,
            'fields'       => 'id,name,picture,access_token,instagram_business_account',
            'limit'        => config('instagramGraph.per_page'),
        ];

        try {
            $response = Http::timeout(config('queue.timeout'))->get($query, $options)->throw();

            return $response->json() ?? [];
        } catch (Exception $e) {
            $this->checkAndHandleServiceAuthorisation();
            $this->log($e->getMessage(), 'error');

            return [];
        }
    }

    public function getNewDownloadUrlGraphApi(File $file): ?string
    {
        try {
            $response = Http::timeout(config('queue.timeout'))->get(config('instagramGraph.query_base_url') . '/' . $file->remote_service_file_id, [
                'access_token' => $this->service->access_token,
                'fields'       => 'media_url',
            ])->throw();

            $data = json_decode($response->getBody()->getContents(), true);

            if ($mediaUrl = data_get($data, 'media_url')) {
                $file->metas()->where('key', MetaType::extra->value)->update(['value->source_link' => $mediaUrl]);
            }

            return $mediaUrl;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return null;
        }
    }

    public function handleTokenExpirationGraphApi(): void
    {
        // Graph API does not use refresh tokens.
    }

    public function listFolderContentGraphApi(?array $request): iterable
    {
        $folderId = data_get($request, 'folder_id') ?? 'root';

        if ($folderId === 'root') {
            // If no or root input, get available pages
            $instagramPages = [];
            $nextUrl = null;

            do {
                $facebookPages = $this->getPagesGraphApi($nextUrl);
                $newInstagramPages = collect(data_get($facebookPages, 'data', []))
                    ->reject(fn ($page) => empty(data_get($page, 'instagram_business_account.id')))
                    ->values()
                    ->map(function ($page) {
                        unset($page['access_token']);

                        return [
                            'id'    => data_get($page, 'instagram_business_account.id'),
                            'isDir' => true,
                            'name'  => sprintf('%s (%s)', $page['name'] ?? '', $page['id'] ?? ''),
                        ] + $page;
                    })->toArray();

                $instagramPages = [...$instagramPages, ...$newInstagramPages];
                $nextUrl = data_get($facebookPages, 'paging.next');
            } while (filled($nextUrl) && count($instagramPages) < config('manager.folder_modal_pagination_limit'));

            return $instagramPages;
        }

        return []; // Only display page level
    }

    public function isServiceAuthorised(): bool
    {
        $response = Http::timeout(config('queue.timeout'))->get(config('instagramGraph.query_base_url') . '/me/accounts', [
            'access_token' => $this->service->access_token,
            'fields'       => 'id',
            'limit'        => 1,
        ]);

        if ($response->failed() || empty(data_get($response->json(), 'data'))) {
            return false;
        }

        return true;
    }

    public function checkAndHandleServiceAuthorisation(): void
    {
        if ($this->isServiceAuthorised() === false) {
            $this->log("Service unauthorization triggered: name={$this->service?->name}, id={$this->service?->id}", 'warning');
            $this->service?->update(['status' => IntegrationStatus::UNAUTHORIZED]);
        }
    }
}
