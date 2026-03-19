<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Onedrive;

use Error;
use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use Illuminate\Support\Str;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotRefreshToken;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use Illuminate\Http\Client\ConnectionException;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\MariusCucuruz\DAMImporter\Integrations\Concerns\ManagesOAuthTokens;
use MariusCucuruz\DAMImporter\Interfaces\HasRateLimit;
use MariusCucuruz\DAMImporter\Pagination\PaginationType;
use MariusCucuruz\DAMImporter\Traits\ServiceRateLimiter;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use MariusCucuruz\DAMImporter\Pagination\PaginatedResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;

class Onedrive extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata, HasRateLimit, HasSettings, IsTestable
{
    use ManagesOAuthTokens;
    use ServiceRateLimiter;

    protected function getPaginationType(): PaginationType
    {
        return PaginationType::Token;
    }

    protected function getRootFolders(): array
    {
        return [
            ['id' => '/me/drive/root/children'],
            ['id' => '/me/drive/sharedWithMe'],
        ];
    }

    protected function fetchPage(?string $folderId, mixed $cursor, array $folderMeta = []): PaginatedResponse
    {
        $response = $this->getItemsFromPath($folderId, $cursor);
        $items = collect(data_get($response, 'value', []));

        // Subfolders are folders with children
        $subfolders = $items
            ->filter(fn ($item) => data_get($item, 'folder', false)
                && (int) data_get($item, 'folder.childCount', 0) > 0)
            ->map(fn ($folder) => ['id' => "/me/drive/items/{$folder['id']}/children"])
            ->values()
            ->toArray();

        // Files have download URL and no folder property
        $files = $items
            ->filter(fn ($item) => ! data_get($item, 'folder', false)
                && isset($item['@microsoft.graph.downloadUrl']))
            ->values()
            ->toArray();

        // Get next cursor from nextLink
        $nextLink = data_get($response, '@odata.nextLink');
        $nextCursor = $nextLink ? $this->getSkipTokenFromUrl($nextLink) : null;
        $nextCursor = $nextCursor ?: null; // Convert false to null

        return new PaginatedResponse($files, $subfolders, $nextCursor);
    }

    protected function transformItems(array $items): array
    {
        // Items are already in correct format from OneDrive API
        return $items;
    }

    protected function filterSupportedExtensions(array $items): array
    {
        return collect($items)
            ->filter(fn ($item) => in_array(
                pathinfo(data_get($item, 'name'), PATHINFO_EXTENSION),
                config('manager.meta.file_extensions')
            ))
            ->values()
            ->toArray();
    }

    public function paginate(?array $request = []): void
    {
        $folders = data_get($request, 'folder_ids', []);

        if (empty($folders)) {
            // Handle root sync
            $this->getFolderFilesAndDispatchJobs('/me/drive/root/children');
            $this->getFolderFilesAndDispatchJobs('/me/drive/sharedWithMe');

            return;
        }

        collect($folders)
            ->each(fn ($folder) => match ($folder) {
                'My Files'       => $this->getFolderFilesAndDispatchJobs('/me/drive/root/children'),
                'Shared With Me' => $this->getFolderFilesAndDispatchJobs('/me/drive/sharedWithMe'),
                default          => $this->getFolderFilesAndDispatchJobs("/me/drive/items/{$folder}/children"),
            });
    }

    public function getFolderFilesAndDispatchJobs($path = null, $skipToken = null)
    {
        // Use the base trait's pagination logic via paginateFolder
        // but only for the initial call (no skipToken)
        if ($skipToken === null) {
            $this->paginateFolder($path, []);

            return null;
        }

        // For recursive calls with skipToken, use original logic for backward compatibility
        $response = $this->getItemsFromPath($path, $skipToken);
        $nextLink = data_get($response, '@odata.nextLink');
        $items = collect(data_get($response, 'value'));

        $items->filter(fn ($item) => data_get($item, 'folder', false)
            && (int) data_get($item, 'folder.childCount', 0) > 0)
            ->each(fn ($folder) => $this->getFolderFilesAndDispatchJobs("/me/drive/items/{$folder['id']}/children"));

        $files = $items->filter(fn ($item) => ! data_get($item, 'folder', false)
            && isset($item['@microsoft.graph.downloadUrl'])
            && in_array(pathinfo(data_get($item, 'name'), PATHINFO_EXTENSION), config('manager.meta.file_extensions'))
        )->toArray();

        if (filled($files)) {
            $this->dispatch($files, $path);
        }

        if (empty($nextLink) || ! $skipToken = $this->getSkipTokenFromUrl($nextLink)) {
            return null;
        }

        return $this->getFolderFilesAndDispatchJobs($path, $skipToken);
    }

    public function getSkipTokenFromUrl($url = null): bool|string
    {
        $urlQueryString = parse_url($url, PHP_URL_QUERY);

        if (empty($urlQueryString)) {
            return false;
        }

        parse_str($urlQueryString, $urlQueryArray);

        $skipToken = data_get($urlQueryArray, '$skiptoken');

        if (empty($skipToken) || empty($skipToken)) {
            return false;
        }

        return $skipToken;
    }

    public ?string $clientId = null;

    public ?string $clientSecret = null;

    public ?string $tenantId = null;

    public ?string $redirectUri = null;

    public ?string $accessToken = null;

    public function initialize(): void
    {
        $settings = $this->getSettings();

        $this->clientId = data_get($settings, 'clientId');
        $this->clientSecret = data_get($settings, 'clientSecret');
        $this->tenantId = data_get($settings, 'tenantId');
        $this->redirectUri = data_get($settings, 'redirectUri');

        $this->validateSettings();
    }

    public function getSettings(?array $customKeys = []): array
    {
        $settings = parent::getSettings($customKeys);

        $clientId = $settings['ONEDRIVE_CLIENT_ID'] ?? config('onedrive.client_id');
        $clientSecret = $settings['ONEDRIVE_SECRET'] ?? config('onedrive.client_secret');
        $tenantId = $settings['ONEDRIVE_TENANT_ID'] ?? config('onedrive.tenant_id');
        $redirectUri = config('onedrive.redirect_uri');

        return compact('clientId', 'clientSecret', 'tenantId', 'redirectUri');
    }

    public function validateSettings(): bool
    {
        throw_if(empty($this->clientId), InvalidSettingValue::make('Client Id'), 'Client Id is missing!');
        throw_if(empty($this->clientSecret), InvalidSettingValue::make('Client Secret'), 'Client Secret is missing!');
        throw_if(empty($this->tenantId), InvalidSettingValue::make('Tenant Id'), 'Tenant Id is missing!');
        throw_if(empty($this->redirectUri), InvalidSettingValue::make('Redirect Uri'), 'Redirect Uri is missing!');

        return true;
    }

    protected function getTokenEndpoint(): string
    {
        return str_replace(
            '{tenant_id}',
            $this->tenantId,
            config('onedrive.token_url')
        );
    }

    protected function getClientCredentials(): array
    {
        return [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
    }

    protected function refreshAccessToken(): void
    {
        try {
            $response = Http::asForm()->post($this->getTokenEndpoint(), [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->service->refresh_token,
                'redirect_uri'  => $this->redirectUri,
                ...$this->getClientCredentials(),
            ]);

            if ($response->failed()) {
                $errorCode = $response->json('error') ?? $response->status();
                logger()->error('OneDrive token refresh failed', [
                    'service_id'        => $this->service?->id,
                    'status'            => $response->status(),
                    'error'             => $response->json('error'),
                    'error_description' => $response->json('error_description'),
                ]);

                throw new CouldNotRefreshToken("Token refresh failed: {$errorCode}");
            }

            $this->persistRefreshedTokens($response->json());
        } catch (CouldNotRefreshToken $e) {
            $this->markServiceUnauthorized();

            throw $e;
        } catch (Exception $e) {
            $this->markServiceUnauthorized();
            logger()->error('OneDrive token refresh exception', [
                'service_id' => $this->service?->id,
                'exception'  => $e->getMessage(),
            ]);

            throw new CouldNotRefreshToken('Token refresh failed due to an unexpected error', previous: $e);
        }
    }

    protected function persistRefreshedTokens(array $response): void
    {
        $this->accessToken = data_get($response, 'access_token');

        $this->service->update([
            'access_token'  => $this->accessToken,
            'refresh_token' => data_get($response, 'refresh_token'),
            'expires'       => data_get($response, 'expires_in')
                ? now()->addSeconds(data_get($response, 'expires_in'))->getTimestamp()
                : null,
            'refresh_token_expires_at' => now()->addDays(90),
            'status'                   => IntegrationStatus::ACTIVE,
        ]);
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        try {
            $queryString = http_build_query([
                'response_type' => 'code',
                'client_id'     => $this->clientId,
                'redirect_uri'  => $this->redirectUri,
                'scope'         => config('onedrive.scope'),
                'prompt'        => 'select_account',
                'state'         => json_encode(['settings' => $this->settings?->pluck('id')?->toArray()]),
            ]);

            $requestUrl = config('onedrive.oauth_base_url') . "/{$this->tenantId}/oauth2/v2.0/authorize?{$queryString}";
            $this->redirectTo($requestUrl);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        throw_if(empty(request('code')), CouldNotGetToken::class, 'Invalid token response');

        try {
            $response = Http::timeout(config('queue.timeout'))->asForm()->post(config('onedrive.oauth_base_url') . "/{$this->tenantId}/oauth2/v2.0/token", [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code'          => request('code'),
                'redirect_uri'  => $this->redirectUri,
                'grant_type'    => 'authorization_code',
            ])->throw();

            $body = $response->json();
            $this->accessToken = data_get($body, 'access_token');

            return new TokenDTO([
                'access_token'             => $this->accessToken,
                'refresh_token'            => data_get($body, 'refresh_token'),
                'expires'                  => data_get($body, 'expires_in') ? now()->addSeconds(data_get($body, 'expires_in')) : null,
                'token_type'               => data_get($body, 'token_type'),
                'refresh_token_expires_at' => now()->addDays(90)->toDateTimeString(),
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    public function getUser(): ?UserDTO
    {
        $this->incrementAttempts();

        try {
            $response = $this->http()->get(config('onedrive.query_base_url') . '/me')->throw();

            $body = $response->json();
            $userPhotoPath = $this->getUserPhotoPath();

            return new UserDTO([
                'email' => data_get($body, 'mail') ?? data_get($body, 'userPrincipalName'),
                'photo' => $userPhotoPath,
                'name'  => data_get($body, 'displayName') ?? data_get($body, 'userPrincipalName'),
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return new UserDTO([]);
        }
    }

    public function getUserPhotoPath(): ?string
    {
        try {
            $response = Http::timeout(config('queue.timeout'))->withToken($this->accessToken)
                ->accept('image/jpeg')
                ->get(config('onedrive.query_base_url') . '/me/photo/$value')
                ->throw();

            if ($response->successful()) {
                return $this->uploadThumbnail($response->body());
            }

            if ($response->notFound()) {
                // No photo uploaded
                return null;
            }
        } catch (Exception $e) {
            $this->log("Error getting account photo: {$e->getMessage()}");
        }

        return null;
    }

    public function uploadThumbnail(mixed $file): ?string
    {
        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            str()->random(6),
            str()->random(6) . '.jpg'
        );

        if (data_get($file, 'thumbnail') && $file = $this->getFileThumbnail($file)) {
            $this->storage->put($thumbnailPath, $file);

            return $thumbnailPath;
        }

        return null;
    }

    public function http(): PendingRequest
    {
        $this->handleTokenExpiration();

        return Http::maxRedirects(10)
            ->timeout(30)
            ->withUserAgent('Medialake OneDrive API Client/1.0')
            ->asJson()
            ->withToken($this->service?->access_token ?? $this->accessToken)
            ->retry(3, 750, function (Exception|Error $e, PendingRequest $request) {
                $this->log('Retrying request after exception: ' . $e->getMessage());

                if ($e->response->status() === 429) {
                    $this->incrementAttempts(3600, config($this->cacheKey())); // Max rate limit and back off for 1 hour.

                    $retryAfter = (int) ($e->response->header('Retry-After') ?? 0);
                    $this->log('Rate limit exceeded.' . ($retryAfter ? 'Retry After ' . $retryAfter . 'seconds' : null));

                    if ($retryAfter > 0 && $retryAfter < 30) {
                        $this->log('Rate limit exceeded. Backing off for ' . $retryAfter . ' seconds.');
                        sleep($retryAfter);

                        return true;
                    }
                }

                if ($e->response->status() === 401) {
                    $this->log('Attempt to refresh OneDrive token after exception: ' . $e->getMessage());

                    try {
                        $this->refreshAccessToken();
                        $request->withToken($this->service->access_token);
                    } catch (CouldNotRefreshToken $ex) {
                        $this->log('Failed to refresh token: ' . $ex->getMessage(), 'error');

                        return false;
                    }
                }

                if ($e->response->status() === 416) {
                    return false;
                }

                return true;
            });
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = data_get($request, 'folder_id') ?? 'root';

        if (empty($folderId) || $folderId === 'root') {
            return collect(['My Files', 'Shared With Me'])
                ->map(fn ($folder) => [
                    'id'    => $folder,
                    'isDir' => true,
                    'name'  => $folder])
                ->toArray();
        }

        return match ($folderId) {
            'My Files'       => $this->getItemsFromPath('/me/drive/root/children', null, true),
            'Shared With Me' => $this->getItemsFromPath('/me/drive/root/sharedWithMe', null, true),
            default          => $this->getItemsFromPath("/me/drive/items/{$folderId}/children", null, true),
        };
    }

    public function getItemsFromPath($path, $skipToken = null, $foldersOnly = false)
    {
        $this->incrementAttempts();

        try {
            $response = $this->http()->withQueryParameters([
                '$top'       => config('onedrive.limit_per_request'),
                '$skiptoken' => $skipToken,
            ])->get(config('onedrive.query_base_url') . $path)->throw();

            if ($response->notFound()) {
                $this->log("Onedrive remote path not found or no longer exists. Path: {$path}.");

                return [];
            }

            if ($foldersOnly) {
                return collect(data_get($response->json(), 'value'))
                    ->filter(fn ($item) => isset($item['folder']) ?? false)
                    ->map(fn ($folder) => [
                        'id'    => data_get($folder, 'id'),
                        'isDir' => true,
                        'name'  => data_get($folder, 'name'),
                    ])->values()->toArray();
            }

            return $response->collect();
        } catch (Exception $e) {
            $this->log("Error fetching items from path {$path}: " . $e->getMessage(), 'error');

            return [];
        }
    }

    public function getMetadataAttributes(?array $properties): array
    {
        return collect($properties)
            ->only(['webUrl', '@microsoft.graph.downloadUrl'])
            ->mapWithKeys(function ($value, $key) {
                if ($key === 'webUrl') {
                    $value = Str::beforeLast($value, '/');
                }

                return [config('onedrive.metadata_fields')[$key] => $value];
            })
            ->toArray();
    }

    public function getNewFileDownloadUrl(File $file): bool|string
    {
        $this->incrementAttempts();

        try {
            $response = $this->http()->get(config('onedrive.query_base_url') . "/me/drive/items/{$file->remote_service_file_id}")->throw();

            return data_get($response->json(), '@microsoft.graph.downloadUrl');
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return false;
        }
    }

    public function handleTemporaryDownload(File $file, $downloadUrl = null): bool|string
    {
        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'No download url present.');

        try {
            $response = $this->http()->get($downloadUrl);

            if ($response?->failed()) {
                $this->log('Failed to download file. File Id: ' . $file->id, 'error');

                return false;
            }

            $this->downstreamToTmpFile($response->body());
        } catch (ConnectionException $e) {
            $this->log("Connection error while downloading file. File Id: {$file->id}. Error: {$e->getMessage()}", 'error');

            return false;
        } catch (Exception $e) {
            $this->log("Error downloading file. File Id: {$file->id}. Error: {$e->getMessage()}", 'error');

            return false;
        }

        $path = $this->downstreamToTmpFile(null, $this->prepareFileName($file));
        $file->update(['size' => $this->getFileSize($path)]);

        return $path;
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        $downloadUrl = $file->getMetaExtra('download_url');

        if ($downloadUrl && $path = $this->handleTemporaryDownload($file, $downloadUrl)) {
            return $path;
        }

        $downloadUrl = $this->getNewFileDownloadUrl($file);

        return $this->handleTemporaryDownload($file, $downloadUrl);
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        $downloadUrl = $file->getMetaExtra('download_url');

        if ($downloadUrl && $path = $this->handleMultipartDownload($file, $downloadUrl)) {
            return $path;
        }

        $downloadUrl = $this->getNewFileDownloadUrl($file);

        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'No download url present.');

        return $this->handleMultipartDownload($file, $downloadUrl);
    }

    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        $downloadUrl = $this->getNewFileDownloadUrl($file);
        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'No download url present.');

        return $this->handleDownloadFromService($file, $downloadUrl);
    }

    public function getFileThumbnail($file): ?string
    {
        $this->incrementAttempts();

        try {
            $response = $this->http()
                ->get(config('onedrive.query_base_url') . "/me/drive/items/{$file['id']}/thumbnails")->throw();

            $body = $response->json();

            $url = data_get($body, 'value.0.large.url')
                ?? data_get($body, 'value.0.medium.url')
                ?? data_get($body, 'value.0.small.url');

            if ($url) {
                $thumbnailContent = file_get_contents($url);

                if ($thumbnailContent === false) {
                    throw new Exception("Failed to fetch thumbnail content from URL: {$url}");
                }

                return $this->uploadThumbnail($thumbnailContent);
            }
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return null;
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $extension = $this->getFileExtensionFromFileName(data_get($file, 'name'));
        $type = $this->getFileTypeFromExtension($extension);

        if ($type !== FunctionsType::Audio->value) {
            $thumbnail = $this->getFileThumbnail($file);
        }

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => data_get($file, 'id'),
            'size'                   => data_get($file, 'size'),
            'name'                   => pathinfo($file['name'], PATHINFO_FILENAME),
            'thumbnail'              => $thumbnail ?? null,
            'mime_type'              => data_get($file, 'file.mimeType'),
            'type'                   => $type,
            'extension'              => $extension,
            'duration'               => data_get($file, 'video.duration'),
            'slug'                   => str()->slug(data_get($file, 'name')),
            'created_time'           => isset($file['createdDateTime'])
                ? Carbon::parse($file['createdDateTime'])->format('Y-m-d H:i:s')
                : null,
            'modified_time' => isset($file['lastModifiedDateTime'])
                ? Carbon::parse($file['lastModifiedDateTime'])->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'OneDrive settings are required');
        abort_if(count(config('onedrive.settings')) !== $settings->count(), 406, 'All Settings must be present');

        return true;
    }
}
