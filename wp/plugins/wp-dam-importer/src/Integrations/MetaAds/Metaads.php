<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Metaads;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Http\Client\PendingRequest;
use MariusCucuruz\DAMImporter\Integrations\Metaads\Traits\HttpClient;
use Illuminate\Support\Facades\RateLimiter;
use MariusCucuruz\DAMImporter\Integrations\Metaads\Traits\Performance;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\RequestException;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Pagination\Paginates;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use Illuminate\Http\Client\ConnectionException;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Interfaces\HasRateLimit;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotQuery;
use MariusCucuruz\DAMImporter\Interfaces\HasPerformance;
use MariusCucuruz\DAMImporter\Traits\ServiceRateLimiter;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Interfaces\HasDateRangeFilter;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;

class Metaads extends SourceIntegration implements CanPaginate, HasDateRangeFilter, HasFolders, HasMetadata, HasPerformance, HasRateLimit, HasSettings
{
    use HttpClient, Paginates, Performance, ServiceRateLimiter;

    public ?string $accessToken = '';

    public ?string $clientId;

    public ?string $clientSecret;

    public ?string $configId;

    public ?string $redirectUri;

    public function initialize(): void
    {
        $settings = $this->getSettings();

        $this->clientId = $settings['clientId'];
        $this->clientSecret = $settings['clientSecret'];
        $this->redirectUri = $settings['redirectUri'];
        $this->configId = $settings['configId'];

        $this->validateSettings();

        $this->accessToken = $this->service?->access_token;
    }

    public function getSettings($customKeys = null): array
    {
        $settings = parent::getSettings($customKeys);

        $clientId = $settings['META_ADS_CLIENT_ID'] ?? config('metaads.client_id');
        $clientSecret = $settings['META_ADS_SECRET'] ?? config('metaads.client_secret');
        $configId = $settings['META_ADS_CONFIG_ID'] ?? config('metaads.config_id');
        $redirectUri = config('metaads.redirect_uri');

        return compact('clientId', 'clientSecret', 'redirectUri', 'configId');
    }

    public function validateSettings(): bool
    {
        throw_if(empty($this->clientId), InvalidSettingValue::make('Client Id'), 'Client Id is missing!');
        throw_if(empty($this->clientSecret), InvalidSettingValue::make('Client Secret'), 'Client Secret is missing!');
        throw_if(empty($this->redirectUri), InvalidSettingValue::make('Redirect Uri'), 'Redirect Uri is missing!');
        throw_if(empty($this->configId), InvalidSettingValue::make('Config Id'), 'Config Id is missing!');

        return true;
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $url = config('metaads.oauth_base_url') . config('metaads.version') . '/dialog/oauth';

        $queryParams = [
            'client_id'    => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'config_id'    => $this->configId,
            'scope'        => config('metaads.scope'),
            'auth_type'    => 'reauthenticate',
            'state'        => $this->generateRedirectOauthState(),
        ];

        throw_unless(
            $url && $this->clientId && $this->configId && $this->redirectUri,
            CouldNotInitializePackage::class,
            'Meta Ad settings are required!'
        );

        $this->redirectTo($url . '?' . http_build_query($queryParams));
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        try {
            $body = $this->http(false)
                ->get(config('metaads.query_base_url') . config('metaads.version') . '/oauth/access_token', [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'redirect_uri'  => $this->redirectUri,
                    'code'          => request('code'),
                ])
                ->json();

            return new TokenDTO($this->storeToken($body));
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    public function storeToken(array $body): array
    {
        $this->accessToken = data_get($body, 'access_token');
        throw_unless(filled($this->accessToken), CouldNotGetToken::class, 'Invalid token response.');

        $expires = data_get($body, 'expires_in')
            ? now()->addSeconds((int) data_get($body, 'expires_in'))->getTimestamp()
            : null;

        $this->getLongLiveToken($this->accessToken);

        return [
            'access_token'  => $this->accessToken,
            'token_type'    => data_get($body, 'token_type'),
            'expires'       => $expires,
            'token'         => null,
            'refresh_token' => null, // Graph API does not use refresh tokens
        ];
    }

    public function getLongLiveToken(string $token): void
    {
        if (empty($token)) {
            return;
        }

        try {
            $body = $this->http(false)
                ->get(config('metaads.query_base_url') . config('metaads.version') . '/oauth/access_token', [
                    'grant_type'        => 'fb_exchange_token',
                    'client_id'         => $this->clientId,
                    'client_secret'     => $this->clientSecret,
                    'fb_exchange_token' => $token,
                ])
                ->json();

            if (filled(data_get($body, 'access_token'))) {
                $this->accessToken = data_get($body, 'access_token');
            }
        } catch (Throwable $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function getUser(): ?UserDTO
    {
        try {
            $me = $this->http()
                ->get(config('metaads.query_base_url') . config('metaads.version') . '/me')
                ->json();

            $userId = data_get($me, 'id');
            throw_unless($userId, CouldNotQuery::class, 'Id not found in response.');

            $body = $this->http()
                ->get(config('metaads.query_base_url') . config('metaads.version') . '/' . $userId, [
                    'fields' => 'id,name,picture.width(720).height(720).as(picture)',
                ])
                ->json();

            throw_unless(filled(data_get($body, 'name')), CouldNotQuery::class, 'Neither name nor email found in the response');

            return new UserDTO([
                'email'   => data_get($body, 'name'), // no email provided
                'photo'   => $this->uploadThumbnail(null, data_get($body, 'picture.data.url')),
                'name'    => data_get($body, 'name'),
                'user_id' => $userId,
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return new UserDTO;
        }
    }

    public function uploadThumbnail(mixed $file = null, $source = null): string
    {
        $id = (string) data_get($file, 'id', str()->random(6));

        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            $id,
            str()->slug($id) . '.jpg'
        );

        $source ??= $this->resolveThumbnailUrl($file);

        if (empty($source)) {
            return '';
        }

        $body = $this->http(false)->get($source)->body();

        $this->storage->put($thumbnailPath, $body);

        return $thumbnailPath;
    }

    private function resolveThumbnailUrl(mixed $file): ?string
    {
        $file = File::findOrFail($file['id']);

        $cdnUrl = $file->type === FunctionsType::Video->value
            ? $file->getMetaExtra('thumbnails.data.0.uri')
            : ($file->getMetaExtra('download_url') ?? $file->getMetaExtra('source_link'));

        if ($this->isUrlValid($cdnUrl)) {
            return $cdnUrl;
        }

        throw_if(empty($file->remote_service_file_id), CouldNotDownloadFile::class, 'File id is not set.');

        $fresh = $this->refreshMetaFile($file);

        $freshThumbnailUrl = $file->type === FunctionsType::Video->value
            ? data_get($fresh, 'thumbnails.data.0.uri')
            : data_get($fresh, 'source');

        return $this->isUrlValid($freshThumbnailUrl) ? $freshThumbnailUrl : null;
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = data_get($request, 'folder_id') ?: 'root';
        $adAccounts = [];
        $after = null;

        if ($folderId === 'root') {
            do {
                $page = $this->getMyAdAccountsPage($after);
                $newFiles = data_get($page, 'data') ?: [];
                $nextUrl = data_get($page, 'paging.next', false);

                if (filled($newFiles)) {
                    $adAccounts = [...$adAccounts, ...$newFiles];
                }

                if (empty($nextUrl)) {
                    break;
                }

                parse_str($nextUrl, $result);
                $after = data_get($result, 'after');
            } while (count($adAccounts) < config('manager.folder_modal_pagination_limit'));
        }

        return collect($adAccounts)->map(function ($folder) {
            $name = (string) data_get($folder, 'name', '');
            $id = data_get($folder, 'id');

            return [
                'id'    => (string) $id,
                'name'  => filled($id) ? "Ad Account: {$name} ({$id})" : "Ad Account: {$name}",
                'isDir' => true,
            ];
        })->all();
    }

    public function getMetadataAttributes(?array $properties): array
    {
        $properties['view_url'] = str(data_get($properties, 'permalink_url'))->start('https://www.facebook.com');
        $properties['download_url'] = data_get($properties, 'source') ?? data_get($properties, 'url');
        $properties['source_link'] = data_get($properties, 'full_picture') ?? data_get($properties, 'source') ?? data_get($properties, 'url');
        $properties['hash'] = data_get($properties, 'hash');

        return $properties;
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        $downloadUrl = $this->getUrl($file);

        throw_unless($downloadUrl, CouldNotDownloadFile::class, "Download URL not provided. File ID: {$file->id}");

        return $this->handleTemporaryDownload($file, $downloadUrl);
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        return $this->downloadTemporary($file, $rendition);
    }

    public function getUrl(File $file): string
    {
        $originalUrl = $file->getMetaExtra('download_url');

        if ($this->isUrlValid($originalUrl)) {
            return $originalUrl;
        }

        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $fresh = $this->refreshMetaFile($file);

        $downloadUrl = data_get($fresh, 'source')
            ?? data_get($fresh, 'full_picture')
            ?? data_get($fresh, 'picture')
            ?? data_get($fresh, 'url');

        throw_unless($this->isUrlValid($downloadUrl), CouldNotDownloadFile::class, 'No valid download URL available.');

        return $downloadUrl;
    }

    protected function refreshMetaFile(File $file): array
    {
        try {
            $base = config('metaads.query_base_url') . config('metaads.version');

            if ($file->type === FunctionsType::Video->value) {
                return $this->http()->get($base . '/' . $file->remote_service_file_id, [
                    'fields' => 'permalink_url,source,picture,thumbnails',
                ])->json();
            }

            if ($parsed = $this->parseAdImageCompositeId($file->remote_service_file_id)) {
                [$actAccountId, $hash] = $parsed;

                $json = $this->http()->get($base . '/' . $actAccountId . '/adimages', [
                    'fields' => 'hash,url,permalink_url,width,height,thumbnails',
                    'hashes' => json_encode([$hash]),
                ])->json();

                $img = data_get($json, 'data.0', []);

                return [
                    'hash'          => data_get($img, 'hash'),
                    'permalink_url' => data_get($img, 'permalink_url'),
                    'full_picture'  => data_get($img, 'url'),
                    'source'        => data_get($img, 'url'),
                    'picture'       => data_get($img, 'url'),
                    'width'         => data_get($img, 'width'),
                    'height'        => data_get($img, 'height'),
                ];
            }

            return $this->http()->get($base . '/' . $file->remote_service_file_id, [
                'fields' => 'permalink_url,full_picture,source,picture,hash',
            ])->json();
        } catch (Exception $e) {
            $this->log("Failed refreshing Meta file from API: {$e->getMessage()}", 'error');

            throw new CouldNotDownloadFile($e->getMessage());
        }
    }

    private function parseAdImageCompositeId(?string $id): ?array
    {
        if (empty($id) || ! str_contains($id, ':')) {
            throw new CouldNotDownloadFile('Invalid Ad Image composite ID format.');
        }

        [$accountId, $hash] = explode(':', $id, 2);

        if (empty($accountId) || empty($hash)) {
            throw new CouldNotDownloadFile('Invalid Ad Image composite ID format.');
        }

        $accountId = str_starts_with($accountId, 'act_')
            ? $accountId
            : 'act_' . $accountId;

        return [$accountId, $hash];
    }

    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        $downloadUrl = $this->getUrl($file);

        return $this->handleDownloadFromService($file, $downloadUrl);
    }

    public function getMyAdAccountsPage($after = null): array
    {
        $this->incrementAttempts();

        try {
            $response = $this->http(false)->get(config('metaads.query_base_url') . '/me/adaccounts', [
                'access_token' => $this->accessToken,
                'fields'       => config('metaads.ad_account_fields'),
                'limit'        => config('metaads.limit_per_request'),
                'after'        => $after,
            ]);

            return $response->json();
        } catch (Exception $e) {
            $this->log('Error getting Meta Ad Accounts: ' . $e->getMessage(), 'error');
            $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);

            return [];
        }
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $id = data_get($file, 'id');

        $type = data_get($file, 'type');
        $extension = data_get($file, 'extension');

        if ($type === FunctionsType::Video->value) {
            $fileTitle = data_get($file, 'title') ?: data_get($file, 'id');
        } else {
            $fileTitle = data_get($file, 'name') ?: data_get($file, 'id');
        }
        $name = pathinfo($fileTitle, PATHINFO_FILENAME);

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => $id,
            'name'                   => ($name !== 'untitled') ? $name : $id,
            'slug'                   => str()->slug($name) ?? $id,
            'mime_type'              => $this->getMimeTypeOrExtension($extension),
            'type'                   => $type,
            'extension'              => $extension,
            'created_time'           => isset($file['created_time']) ? Carbon::parse($file['created_time'])->format('Y-m-d H:i:s') : null,
            'modified_time'          => isset($file['updated_time']) ? Carbon::parse($file['updated_time'])->format('Y-m-d H:i:s') : null,
            'remote_page_identifier' => data_get($file, 'remote_page_id'),
        ]);
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Meta Ads settings are required');
        abort_if(count(config('metaads.settings')) !== $settings->count(), 406, 'All Settings must be present');

        return true;
    }

    public function http($withAccessToken = true): PendingRequest
    {
        $request = Http::maxRedirects(10)
            ->timeout(config('queue.timeout'))
            ->withUserAgent(config('metaads.user_agent'))
            ->asJson()
            ->retry(
                3,
                750,
                function ($exception, PendingRequest $request) use ($withAccessToken) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException && ($response = $exception->response)) {
                        if ($withAccessToken && $response->status() === 401) {
                            $this->log('401 from Meta; marking service unauthorized.', 'error');

                            if (isset($this->service)) {
                                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
                            }

                            return false;
                        }

                        if ($response->serverError() || $response->status() === 429) {
                            return true;
                        }

                        $this->log('Not retrying non-transient HTTP error: ' . $response->status());

                        return false;
                    }

                    return false;
                }
            )
            ->throw();

        if ($withAccessToken && filled($this->accessToken)) {
            $request->withToken($this->accessToken);
        }

        return $request;
    }

    // @note: Time based pagination is not functional with Meta Ads API, presumed bug. Leaving [since, until] in place for future version use. Manual filtering is required.
    public function paginate(array $request = []): void
    {
        $this->setRateLimitRemainder();
        $folders = data_get($request, 'metadata', []);

        if (empty($folders)) {
            $this->log('No folders found for Meta Ads sync', 'error');

            return;
        }

        array_walk($folders, function ($folder) {
            $id = data_get($folder, 'folder_id');
            $startDateInput = data_get($folder, 'start_time');
            $endDateInput = data_get($folder, 'end_time');

            if (! empty($startDateInput) && ! empty($endDateInput)) {
                $this->log("Invalid date range for folder ID: {$id}", 'error');

                return;
            }

            $this->handleAdAccountsMedia($id);
        });
    }

    public function handleAdAccountsMedia(string $id, ?string $pageToken = null): void
    {
        if ($id === 'all') {
            $adAccountsPage = $this->getMyAdAccountsPage($pageToken);
            $adAccounts = data_get($adAccountsPage, 'data') ?? [];

            $nextUrl = data_get($adAccountsPage, 'paging.next');

            if (filled($nextUrl)) {
                $this->handlePagination('all', $nextUrl, fn ($id, $after) => $this->handleAdAccountsMedia($id, $after));
            }
        } else {
            $adAccounts = [
                compact('id'),
            ];
        }

        $this->dispatchVideosFromAdAccount($adAccounts);
        $this->dispatchImagesFromAdAccount($adAccounts);
    }

    public function handlePagination(string $id, string $url, callable $dispatch): void
    {
        parse_str($url, $result);
        $dispatch($id, data_get($result, 'after'));
    }

    public function dispatchVideosFromAdAccount(array $adAccounts, ?string $pageToken = null): void
    {
        collect($adAccounts)->each(function ($adAccount) use ($pageToken) {
            $adAccountId = data_get($adAccount, 'id');
            $data = $this->getAdAccountVideos($adAccountId, $pageToken);
            $files = data_get($data, 'data');

            if (filled($files)) {
                $files = collect($files)
                    ->reject(fn ($file) => $this->isDateSyncFilter && ! $this->isWithinDatePeriod(data_get($file, 'created_time')))
                    ->map(function ($file) use ($adAccountId) {
                        $extension = null;
                        $url = data_get($file, 'source');

                        if (filled($url)) {
                            $extension = $this->getFileExtensionFromRemoteUrl($url);
                        }

                        data_set($file, 'type', FunctionsType::Video->value);
                        data_set($file, 'extension', $extension);
                        data_set($file, 'remote_page_id', $adAccountId);

                        return $file;
                    })
                    ->toArray();

                $filteredFiles = $this->filterSupportedFileExtensions($files);

                if (filled($filteredFiles)) {
                    $this->dispatch($filteredFiles, $adAccountId);
                }
            }

            $nextUrl = data_get($data, 'paging.next');

            if (filled($nextUrl)) {
                $this->handlePagination($adAccountId, $nextUrl, fn ($id, $after) => $this->dispatchVideosFromAdAccount([['id' => $id]], $after));
            }
        });
    }

    public function dispatchImagesFromAdAccount(array $adAccounts, ?string $pageToken = null): void
    {
        collect($adAccounts)->each(function ($adAccount) use ($pageToken) {
            $adAccountId = data_get($adAccount, 'id');
            $data = $this->getAdAccountImages($adAccountId, $pageToken);
            $files = data_get($data, 'data');

            if (filled($files)) {
                $files = collect($files)
                    ->reject(fn ($file) => $this->isDateSyncFilter && ! $this->isWithinDatePeriod(data_get($file, 'created_time')))
                    ->map(function ($file) use ($adAccountId) {
                        $extension = null;
                        $url = data_get($file, 'url');

                        if (filled($url)) {
                            $extension = $this->getFileExtensionFromRemoteUrl($url);
                        }

                        data_set($file, 'type', FunctionsType::Image->value);
                        data_set($file, 'extension', $extension);
                        data_set($file, 'remote_page_id', $adAccountId);

                        return $file;
                    })
                    ->toArray();

                $filteredFiles = $this->filterSupportedFileExtensions($files);

                if (filled($filteredFiles)) {
                    $this->dispatch($filteredFiles, $adAccountId);
                }
            }

            $nextUrl = data_get($data, 'paging.next');

            if (filled($nextUrl)) {
                $this->handlePagination($adAccountId, $nextUrl, fn ($id, $after) => $this->dispatchImagesFromAdAccount([['id' => $id]], $after));
            }
        });
    }

    public function getAdAccountVideos(string $adAccountId, $after = null): array
    {
        $this->incrementAttempts();

        // https://developers.facebook.com/docs/marketing-api/reference/ad-account/advideos/
        try {
            return $this->http(false)
                ->get(config('metaads.query_base_url') . config('metaads.version') . "/{$adAccountId}/advideos", [
                    'access_token' => filled($this->accessToken) ? $this->accessToken : $this->service->access_token,
                    'fields'       => config('metaads.video_fields'),
                    'limit'        => config('metaads.limit_per_request'),
                    'since'        => $this->syncFilterDateRange?->start->timestamp,
                    'until'        => $this->syncFilterDateRange?->end->timestamp,
                    'after'        => $after,
                ])
                ->json() ?? [];
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }

            $this->log('Error getting Meta Ad Videos: ' . $e->getMessage(), 'error');

            return [];
        }
    }

    public function getAdAccountImages(string $adAccountId, $after = null): array
    {
        $this->incrementAttempts();

        // https://developers.facebook.com/docs/marketing-api/reference/ad-image/
        try {
            return $this->http(false)
                ->get(config('metaads.query_base_url') . config('metaads.version') . "/{$adAccountId}/adimages", [
                    'access_token' => $this->accessToken !== '' ? $this->accessToken : $this->service->access_token,
                    'fields'       => config('metaads.image_fields'),
                    'limit'        => config('metaads.limit_per_request'),
                    'since'        => $this->syncFilterDateRange?->start->timestamp,
                    'until'        => $this->syncFilterDateRange?->end->timestamp,
                    'after'        => $after,
                ])
                ->json() ?? [];
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }

            $this->log('Error getting Meta Ad Images: ' . $e->getMessage(), 'error');

            return [];
        }
    }

    public function setRateLimitRemainder(): void
    {
        $max = (int) config('metaads.rate_limit');
        $amount = 0;

        try {
            $response = $this->http(false)->get(
                config('metaads.query_base_url') . config('metaads.version') . '/me',
                [
                    'access_token' => $this->accessToken !== '' ? $this->accessToken : $this->service->access_token,
                    'Accept'       => 'application/json',
                ]
            );

            $headers = $response->headers();

            $usage = data_get($headers, 'x-app-usage.0');
            $count = $usage ? (int) data_get(json_decode($usage, true), 'call_count', 0) : 0;

            if ($count > 0) {
                $remainingCalls = max(0, $max - ($max * $count / 100));
                $amount = max(0, $max - $remainingCalls);
            }
        } catch (Exception $e) {
            logger($e->getMessage());
            $amount = $max;
        }

        RateLimiter::remaining($this->cacheKey(), $amount);
    }
}
