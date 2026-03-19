<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Bynder;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use MariusCucuruz\DAMImporter\Enums\SettingsRequired;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Pagination\Paginates;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Interfaces\HasRateLimit;
use MariusCucuruz\DAMImporter\Pagination\PaginationType;
use MariusCucuruz\DAMImporter\Traits\ServiceRateLimiter;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use MariusCucuruz\DAMImporter\Pagination\PaginatedResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Interfaces\HasRemoteServiceId;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;

class Bynder extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata, HasRateLimit, HasRemoteServiceId, HasSettings
{
    use Paginates, ServiceRateLimiter;

    protected function getPaginationType(): PaginationType
    {
        return PaginationType::Page;
    }

    protected function getPageStart(): int
    {
        return config('bynder.page_start', 1);
    }

    protected function getRootFolders(): array
    {
        // Bynder should always have folders specified
        // Returning empty to avoid syncing all brands unintentionally
        return [];
    }

    protected function fetchPage(?string $folderId, mixed $cursor, array $folderMeta = []): PaginatedResponse
    {
        $queryUrl = data_get($folderMeta, 'metadata.query_url');
        $page = $cursor ?? $this->getPageStart();

        $data = $this->getBrandAssets(brandId: $folderId, queryUrl: $queryUrl, page: $page);
        $assets = data_get($data, 'media', []);

        // For page-based, nextCursor is the presence of items (continue while items exist)
        $nextCursor = filled($assets) && count($assets) > 0 ? true : null;

        return new PaginatedResponse($assets, [], $nextCursor);
    }

    protected function transformItems(array $items): array
    {
        // Items are already in the correct format from Bynder API
        return $items;
    }

    protected function filterSupportedExtensions(array $items): array
    {
        // Bynder uses a custom extension path
        return $this->filterSupportedFileExtensions($items, 'extension.0');
    }

    public function paginate(array $request = []): void
    {
        $folders = data_get($request, 'metadata', []);
        $filteredFolders = $this->filterDisabledQueries($folders);

        if (! empty($folders)) {
            $this->handleBrandAssets(
                collect($filteredFolders)
                    ->map(fn ($folder) => [...$folder, 'id' => data_get($folder, 'folder_id')])
                    ->toArray()
            );

            return;
        }

        // This should ideally be avoided by always specifying folders to sync
        // as it could cause issues if a service is created without folders
        // and then a user initiates a sync.
    }

    public function handleBrandAssets(array $brands = []): void
    {
        collect($brands)->each(function ($brand) {
            if (! $id = data_get($brand, 'id')) {
                return;
            }

            $this->paginateFolder($id, $brand);
        });
    }

    private const array THUMBNAIL_PATHS = [
        'thumbnails.thul',
        'thumbnails.webimage',
        'thumbnails.mini',
    ];

    public ?string $clientId;

    public ?string $clientSecret;

    public ?string $bearerToken;

    public string $domainUrl;

    public string $accessToken;

    public ?SettingsRequired $credentialsType;

    public function initialize(): void
    {
        $this->credentialsType = SettingsRequired::tryFrom((string) $this->settings?->firstWhere('name', 'BYNDER_ACCOUNT_TYPE')?->payload) ?? SettingsRequired::OAUTH;
        $credentialRelatedSettingKey = config("bynder.api_setting_keys.{$this->credentialsType->value}");
        $this->customSettingKeys = array_keys(config("bynder.settings.{$credentialRelatedSettingKey}"));

        $settings = $this->getSettings($this->customSettingKeys);

        $this->clientId = data_get($settings, 'clientId');
        $this->clientSecret = data_get($settings, 'clientSecret');
        $this->bearerToken = data_get($settings, 'bearerToken');
        $this->domainUrl = $settings['domainUrl'];

        $this->validateSettings();
    }

    public function getSettings($customKeys = null): array
    {
        $settings = parent::getSettings($customKeys);

        $clientId = $settings['BYNDER_CLIENT_ID'] ?? config('bynder.client_id');
        $clientSecret = $settings['BYNDER_CLIENT_SECRET'] ?? config('bynder.client_secret');
        $domainUrl = $settings['BYNDER_DOMAIN_URL'] ?? config('bynder.domain_url');
        $bearerToken = $settings['BYNDER_BEARER_TOKEN'] ?? config('bynder.bearer_token');

        return match ($this->credentialsType) {
            SettingsRequired::TOKEN => compact('domainUrl', 'bearerToken'),
            SettingsRequired::OAUTH => compact('clientId', 'clientSecret', 'domainUrl'),
            default                 => [],
        };
    }

    public function validateSettings(): bool
    {
        if ($this->credentialsType === SettingsRequired::TOKEN) {
            throw_if(empty($this->bearerToken), InvalidSettingValue::make('Bearer Token'), 'Bearer Token is missing!');
        }

        if ($this->credentialsType === SettingsRequired::OAUTH) {
            throw_if(empty($this->clientId), InvalidSettingValue::make('Client Id'), 'Client Id is missing!');
            throw_if(empty($this->clientSecret), InvalidSettingValue::make('Client Secret'), 'Client Secret is missing!');
        }

        throw_if(filter_var($this->domainUrl, FILTER_VALIDATE_URL) === false, InvalidSettingValue::make('Domain Url'), 'Domain Url is missing!');

        return true;
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $authUrl = Path::join(config('app.url'), config('bynder.name') . '-redirect');

        if (isset($settings) && $settings->count()) {
            $authUrl .= '?' . http_build_query([
                'state' => json_encode(['settings' => $this->settings->pluck('id')?->toArray()]),
            ]);
        }

        $this->redirectTo($authUrl);
    }

    public function http(bool $withToken = true, ?bool $incrementRateLimit = true): PendingRequest
    {
        if ($incrementRateLimit) {
            $this->incrementAttempts(300); // 5 minute decay
        }

        return Http::maxRedirects(10)
            ->timeout(config('queue.timeout'))
            ->withUserAgent('Medialake Bynder API Client/1.0')
            ->throw()
            ->asJson()
            ->when($withToken, fn (PendingRequest $result) => $result
                ->withToken($this->service->access_token ?? $this->accessToken))
            ->retry(3, 750, function (Exception $e, PendingRequest $request) use ($withToken) {
                if (! $withToken) {
                    return true;
                }

                if (method_exists($e, 'getResponse')
                    && $e->getResponse()
                    && $e->getResponse()->getStatusCode() === Response::HTTP_NOT_FOUND) {
                    return false;
                }

                $this->log('Attempt to refresh Bynder token after exception: ' . $e->getMessage());

                $this->service->update($this->getTokens()->toArray());
                $request->withToken($this->service->access_token);

                return true;
            });
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        if ($this->credentialsType === SettingsRequired::TOKEN) {
            $this->accessToken = $this->bearerToken;

            return new TokenDTO([
                'access_token' => $this->bearerToken,
                'expires'      => null,
            ]);
        }

        try {
            $response = $this->http(false)
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post(Path::join($this->domainUrl, config('bynder.authorize_api_version'), config('bynder.auth_token_path')), [
                    'grant_type' => 'client_credentials',
                ]);

            $body = $response->json();
            throw_unless(data_get($body, 'access_token'), CouldNotGetToken::class, 'Invalid token response.');
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }

        $this->accessToken = data_get($body, 'access_token');
        $expires = data_get($body, 'expires_in') ? now()->addseconds((int) data_get($body, 'expires_in'))->getTimestamp() : null;

        return new TokenDTO([
            'access_token' => $this->accessToken,
            'expires'      => $expires,
        ]);
    }

    public function getUser(): ?UserDTO
    {
        if ($this->credentialsType === SettingsRequired::TOKEN) {
            $settingUser = $this->settings->load('user')->first()?->user;

            return new UserDTO([
                'email' => $settingUser ? $settingUser->email : md5((string) $this->settings->firstWhere('name', 'BYNDER_BEARER_TOKEN')?->payload),
                'name'  => $settingUser?->name,
            ]);
        }

        try {
            $response = $this->http()->get(Path::join($this->domainUrl, 'api', config('bynder.query_api_version'), 'currentUser'));
            $body = $response->json();

            return new UserDTO([
                'email' => data_get($body, 'id') . '-' . data_get($body, 'profileId') . '-' . $this->domainUrl,
                'name'  => data_get($body, 'username') ?? data_get($body, 'email'),
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return new UserDTO;
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = data_get($request, 'folder_id') ?: 'root';

        if ($folderId !== 'root') {
            return [];
        }

        $brands = collect($this->getBrands())->map(fn ($brand) => [
            'id'    => data_get($brand, 'id'),
            'name'  => data_get($brand, 'name'),
            'isDir' => true,
        ])->values()->toArray();

        return [...$brands];
    }

    public function getBrands(): array
    {
        try {
            // Pagination not supported for Retrieve Brand Endpoint currently. Docs: https://bynder.docs.apiary.io/#reference/groups/group-operations/retrieve-brands
            $response = $this->http()
                ->get(Path::join($this->domainUrl, 'api', config('bynder.query_api_version'), 'brands'));

            return $response->json();
        } catch (Exception $e) {
            if ($e->getCode() === Response::HTTP_UNAUTHORIZED) {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }
            $this->log($e->getMessage(), 'error');
        }

        return [];
    }

    public function getBrandAssets(?string $brandId = null, ?string $queryUrl = null, int $page = 0): array
    {
        $queryParams = [
            'limit' => config('bynder.limit'),
            'page'  => $page,
            'total' => 1,
        ];

        if ($queryUrl) {
            $queryString = parse_url($queryUrl, PHP_URL_QUERY);
            parse_str($queryString, $customQueryParams);
            $queryParams = [...$queryParams, ...$customQueryParams];
        } elseif ($brandId) {
            $queryParams['brandId'] = $brandId;
        }

        try {
            $response = $this->http()
                ->withQueryParameters($queryParams)
                ->get(Path::join($this->domainUrl, 'api', config('bynder.query_api_version'), 'media'));

            return $response->json();
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            if (empty($queryUrl)) {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }
        }

        return [];
    }

    public function getDownloadUrlFromMetas(File $file): ?string
    {
        $metadata = $file->getMetaExtra();

        if (empty($metadata)) {
            return null;
        }

        $downloadUrl = match ($file->type) {
            FunctionsType::Video->value => data_get($metadata, 'videoPreviewURLs.0'),
            FunctionsType::Image->value => data_get($metadata, 'thumbnails.webimage'),
            FunctionsType::Audio->value,
            FunctionsType::PDF->value => data_get($metadata, 'downloadUrl'),
            default                   => null,
        };

        if (empty($downloadUrl) || ! $this->isUrlValid($downloadUrl)) {
            $this->log("Unable to get valid download URL from saved metas for file. File ID: {$file->id}, File Type: {$file->type}.");

            return null;
        }

        return $downloadUrl;
    }

    public function getNewS3DownloadUrl(File $file): ?string
    {
        $url = Path::join($this->domainUrl, 'api', config('bynder.query_api_version'), 'media', $file->remote_service_file_id, 'download');

        try {
            $response = $this->http()->get($url)->json();
            $s3Url = data_get($response, 's3_file');

            return $this->isUrlValid($s3Url) ? $s3Url : null;
        } catch (Exception $e) {
            $this->log("Unable to get a valid download URL from Bynder for file. File ID: {$file->id}, File Type: {$file->type}." . $e->getMessage(), 'error');

            return null;
        }
    }

    public function getNewMetaInformation(File $file): ?array
    {
        $url = Path::join($this->domainUrl, 'api', config('bynder.query_api_version'), 'media', $file->remote_service_file_id);

        try {
            return $this->http()->get($url)->json();
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return null;
        }
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        $storedDownloadUrl = $this->getDownloadUrlFromMetas($file);

        if (filled($storedDownloadUrl)
            && ($path = $this->handleTemporaryDownload($file, $storedDownloadUrl))) {
            return $path;
        }

        $newDownloadUrl = $this->getNewS3DownloadUrl($file);

        return filled($newDownloadUrl) ? $this->handleTemporaryDownload($file, $newDownloadUrl) : false;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        $downloadUrl = $this->getDownloadUrlFromMetas($file) ?? $this->getNewS3DownloadUrl($file);

        if (empty($downloadUrl)) {
            return false;
        }

        return $this->handleMultipartDownload($file, $downloadUrl);
    }

    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        $url = $this->getDownloadUrlFromMetas($file)
            ?: $this->getNewS3DownloadUrl($file);

        throw_unless(filled($url), CouldNotDownloadFile::class, 'Download URL not found!');

        return $this->handleDownloadFromService($file, $url);
    }

    public function uploadThumbnail(mixed $file = null, $source = null): string
    {
        $id = (string) data_get($file, 'id', str()->random(6));

        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            $id,
            str()->slug($id) . '.jpg'
        );

        $thumbnails = data_get($file, 'thumbnails');

        if (is_array($thumbnails) && empty($thumbnails)) {
            $this->log("File has empty thumbnails array, skipping thumbnail generation. File ID: {$id}", 'info');

            return '';
        }

        try {
            $source ??= $this->resolveThumbnailUrl($file);
        } catch (Exception $e) {
            $this->log("Could not resolve thumbnail URL for file {$id}: " . $e->getMessage(), 'warning');

            return '';
        }

        if (empty($source)) {
            return '';
        }

        try {
            $body = $this->http(false)->get($source)->body();
            $this->storage->put($thumbnailPath, $body);

            return $thumbnailPath;
        } catch (Exception $e) {
            $this->log("Error downloading thumbnail for file {$id}: " . $e->getMessage(), 'error');

            return '';
        }
    }

    private function resolveThumbnailUrl(mixed $file): ?string
    {
        $thumbnailFromData = $this->findFirstValidThumbnailFromMeta($file);

        if ($thumbnailFromData !== null) {
            return $thumbnailFromData;
        }

        $thumbnails = data_get($file, 'thumbnails');

        if (is_array($thumbnails) && empty($thumbnails)) {
            return null;
        }

        $fileModel = $file instanceof File ? $file : File::findOrFail($file['id']);

        $cdnUrl = $this->findFirstValidThumbnailFromModel($fileModel);

        if ($cdnUrl !== null) {
            return $cdnUrl;
        }

        if (empty($fileModel->remote_service_file_id)) {
            $this->log("Cannot fetch fresh meta: remote_service_file_id is not set for file {$fileModel->id}", 'warning');

            return null;
        }

        $freshMeta = $this->getNewMetaInformation($fileModel);

        if ($freshMeta === null) {
            return null;
        }

        $freshThumbnails = data_get($freshMeta, 'thumbnails');

        if (is_array($freshThumbnails) && empty($freshThumbnails)) {
            $this->log("Fresh meta also has empty thumbnails for file {$fileModel->id}", 'info');

            return null;
        }

        return $this->findFirstValidThumbnailFromMeta($freshMeta);
    }

    private function findFirstValidThumbnailFromModel(File $file): ?string
    {
        foreach (self::THUMBNAIL_PATHS as $path) {
            $url = $file->getMetaExtra($path);

            if (is_string($url) && $this->isUrlValid($url)) {
                return $url;
            }
        }

        return null;
    }

    private function findFirstValidThumbnailFromMeta(mixed $meta): ?string
    {
        if ($meta === null) {
            return null;
        }

        foreach (self::THUMBNAIL_PATHS as $path) {
            $url = data_get($meta, $path);

            if (is_string($url) && $this->isUrlValid($url)) {
                return $url;
            }
        }

        return null;
    }

    public function testSettings(Collection $settings): bool
    {
        $credentialsRelatedSettingsKey = config('bynder.api_setting_keys.' . $this->credentialsType->value, []);
        $credentialsRelatedSettingsValue = config('bynder.settings')[$credentialsRelatedSettingsKey];

        abort_if($settings->isEmpty(), Response::HTTP_BAD_REQUEST, 'Bynder settings are required');
        abort_if(count($credentialsRelatedSettingsValue) !== ($settings->count() - 1), 406, 'All Settings must be present');

        $domainUrl = $settings->firstWhere('name', 'BYNDER_DOMAIN_URL')?->payload ?? '';
        $urlIsValid = filter_var($domainUrl, FILTER_VALIDATE_URL);

        abort_if(
            ! $urlIsValid,
            406,
            'Looks like your domain URL format is invalid'
        );

        return true;
    }

    public function delay(int $cooldownPeriodSeconds = 300): int
    {
        $maxAttempts = config($this->cacheKey());
        $attempts = RateLimiter::attempts($this->cacheKey());

        if ($maxAttempts && $attempts >= $maxAttempts) {
            return intdiv((int) $attempts, $maxAttempts) * $cooldownPeriodSeconds;
        }

        return 0;
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $name = data_get($file, 'name');
        $extension = data_get($file, 'extension.0') ?: $this->getFileExtensionFromFileName($name);
        $type = $this->getFileTypeFromExtension($extension);
        $mimeType = $this->getMimeTypeOrExtension($extension);

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => data_get($file, 'id'),
            'size'                   => data_get($file, 'fileSize'),
            'name'                   => pathinfo($name, PATHINFO_FILENAME),
            'mime_type'              => $mimeType,
            'type'                   => $type,
            'extension'              => $extension,
            'slug'                   => str()->slug($name),
            'created_time'           => isset($file['dateCreated'])
                ? Carbon::parse($file['dateCreated'])->format('Y-m-d H:i:s')
                : null,
            'modified_time' => isset($file['dateModified'])
                ? Carbon::parse($file['dateModified'])->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public function getRemoteServiceId(): string
    {
        return ($this->bearerToken ?? '') . $this->domainUrl;
    }
}
