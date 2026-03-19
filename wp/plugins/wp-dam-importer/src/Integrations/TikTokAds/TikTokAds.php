<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Enums\AssetType;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use Illuminate\Routing\Redirector;
use MariusCucuruz\DAMImporter\DTOs\IntegrationDefinition;
use MariusCucuruz\DAMImporter\Enums\FileVisibilityStatus;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\Exceptions\CannotDownloadUrl;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use Illuminate\Http\RedirectResponse;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\TikTokAds\DTOs\ImageData;
use MariusCucuruz\DAMImporter\Integrations\TikTokAds\DTOs\VideoData;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\SourceIntegration;
use Symfony\Component\HttpFoundation\Response;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Enums\MediaStorageType;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Integrations\TikTokAds\Service\TikTokAdsService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;

class TikTokAds extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata
{
    use Loggable;

    public const string DEFAULT_DATE_FORMAT = 'Y-m-d H:i:s';

    public ?array $packageSettings = [];

    public TikTokAdsService $ttService;

    private ?string $accessToken = null;

    public static function definition(): IntegrationDefinition
    {
        return IntegrationDefinition::make(
            self::getServiceName(),
            'TikTok Ads',
            TikTokAdsServiceProvider::class,
            [],
        );
    }

    public function initialize(): static
    {
        $this->startLog();

        $this->ttService = new TikTokAdsService($this->service);

        $configuration = $this->loadConfiguration();

        if (empty($configuration)) {
            throw new InvalidSettingValue('Invalid configuration values for TikTok Ads.');
        }

        $this->packageSettings = data_get($configuration, 'settings', []);

        return $this;
    }

    /** route('services.create')::ServiceController->redirectToOauthApp() */
    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): RedirectResponse|Redirector
    {
        try {
            $authUrl = $this->ttService->makeAuthUrl($this->generateRedirectOauthState());

            return $this->redirectTo($authUrl);
        } catch (Exception $e) {
            $this->log("Error redirecting to Auth URL: {$e->getMessage()}", 'error');

            return $this->redirectToServiceUrl(httpCode: Response::HTTP_NOT_MODIFIED);
        }
    }

    /** manager/src/routes.php::get('/{package}-redirect')::$serviceInstance->getTokens() */
    public function getTokens(?array $tokens = null): TokenDTO
    {
        if (data_get($tokens, 'access_token')) {
            $this->ttService->handleTokenExpiration();

            $currentTokens = $this->service?->oAuthToken();

            $tokens = [
                'response_type'            => null,
                'access_token'             => $currentTokens->accessToken ?? $this->service?->accessToken(),
                'expires'                  => $currentTokens->accessExpiresAt?->format(self::DEFAULT_DATE_FORMAT),
                'refresh_token'            => $currentTokens->refreshToken,
                'refresh_token_expires_at' => $currentTokens->refreshExpiresAt?->format(self::DEFAULT_DATE_FORMAT),
                'refresh_token_updated_at' => $currentTokens->createdAt?->format(self::DEFAULT_DATE_FORMAT),
                'created'                  => $currentTokens->createdAt?->format(self::DEFAULT_DATE_FORMAT),
            ];
        }

        if (empty($tokens) && $authCode = request('code')) {
            $tokens = $this->ttService->fetchAccessTokens($authCode);
        }

        $this->accessToken = data_get($tokens, 'access_token');

        if ($this->service instanceof Service && filled($this->service->access_token)) {
            // validate stored tokens
            return $this->getTokens([
                'access_token'  => $this->service->access_token,
                'refresh_token' => $this->service->refresh_token, // TT Ads do not support refresh_token
            ]);
        }

        return new TokenDTO($tokens);
    }

    /** manager/src/routes.php::get('/{package}-redirect')::$serviceInstance->getUser() */
    public function getUser(?string $email = null): ?UserDTO
    {
        try {
            $userInfo = $this->ttService->fetchUser($this->accessToken);
        } catch (Exception $e) {
            $this->log("Cannot get user info: {$e->getMessage()}", 'warn', null, [$e->getTrace(), $this->service, $this->service?->oAuthToken()]);
            $userInfo = [];
        }

        $userId = data_get($userInfo, 'core_user_id')
            ?? data_get($userInfo, 'open_id')
            ?? data_get($userInfo, 'union_id');

        $profilePic = data_get($userInfo, 'avatar_large_url')
            ?? data_get($userInfo, 'avatar_url')
            ?? data_get($userInfo, 'avatar_url_100');

        $userName = data_get($userInfo, 'display_name')
            ?? data_get($userInfo, 'username');

        $userProps = [
            'user_id' => $userId,
            'email'   => $userId ?? $email,
            'name'    => $userName,
            'photo'   => filled($profilePic)
                ? ($this->uploadFromUrlTo($profilePic) ?? $profilePic)
                : null,
        ];

        return new UserDTO($userProps);
    }

    /**
     * SyncFilesAndFoldersJob::paginated() >> FileMassCreate::mutateAttributes()
     * these results are later loaded into fileProperties()
     */
    public function paginate(?array $request = null): void
    {
        $advertiserIds = $this->ttService->resolveAdvertisersId($request);

        foreach ($advertiserIds as $advertiserId) {
            $fileAtts = [
                'service_id'   => $this->service->id,
                'service_name' => $this->service->customName ?? $this->service->name,
                'user_id'      => $this->service->user->id,
                'team_id'      => $this->service->team->id,
                'import_group' => "{$this->service->id}:{$advertiserId}:" . now()->timestamp,
            ];

            $files = [];

            /** @var VideoData|ImageData $assetDto */
            foreach (iterator_to_array($this->ttService->fetchAllAssetsForAdvertiser($advertiserId)) as $assetDto) {
                if (! $assetDto instanceof VideoData && ! $assetDto instanceof ImageData) {
                    logger()->warning('Asset type not supported', compact('assetDto'));

                    continue;
                }

                // @Marius - For all integrations lets just dispatch the API response of the files here
                // and all file formatting will be done in the fileProperties() method.
                // This way we have a single source of truth for file formatting.
                // We are formatting the files twice at the moment.
                $files[] = [
                    'remote_service_file_id' => $assetDto->fileId,
                    'remote_page_identifier' => $assetDto->advertiserId ?? $advertiserId,
                    'signature'              => $assetDto->signature,
                    'user_id'                => $this->service->user->id,
                    'team_id'                => $this->service->team->id,
                    'service_id'             => $this->service->id,
                    'service_name'           => $this->service->name,
                    'import_group'           => data_get($fileAtts, 'import_group'),
                    'name'                   => $assetDto->fileName,
                    'slug'                   => str()->slug(pathinfo($assetDto->fileName, PATHINFO_FILENAME)),
                    'thumbnail_url'          => $assetDto->thumbnail,  // saved to meta:extra & used for thumbnails
                    'source_link'            => $assetDto->url, // saved to meta:extra & used for downloading
                    'size'                   => $assetDto->size,
                    'duration'               => $assetDto->duration ? ($assetDto->duration * 1000) : null,
                    'extension'              => $assetDto->extension,
                    'mime_type'              => $assetDto->mimeType ?? $this->getMimeTypeOrExtension($assetDto->extension),
                    'type'                   => $this->getFileTypeFromExtension($assetDto->extension) ?? $assetDto->type,
                    'visibility'             => FileVisibilityStatus::PUBLIC,
                    'created_at'             => Carbon::parse(data_get($fileAtts, 'created_at', 'now')),
                    'updated_at'             => Carbon::parse(data_get($fileAtts, 'updated_at', 'now')),
                ];
            }

            $this->dispatch($files, $fileAtts['import_group']);
        }
    }

    /**
     * post('service/folder[s]/{service}')::ServiceController->folder()&&folders()
     * post('service/sync.with.configs/{service}')::ServiceController->syncFoldersWithConfigs()
     */
    public function listFolderContent(?array $request): array
    {
        $folderId = data_get($request, 'folder_id');

        if ($folderId !== 'root' && $folderId !== null) {
            return [];
        }

        $folders = [];

        try {
            $advertisersInfo = $this->ttService->fetchAdvertisers();

            foreach ($advertisersInfo as $advertiserInfo) {
                $accID = data_get($advertiserInfo, 'advertiser_id');
                $accName = data_get($advertiserInfo, 'advertiser_name');

                $folders[] = [
                    'id'     => $accID,
                    'name'   => "Account {$accName} ({$accID})",
                    'parent' => null,
                    'isDir'  => true,
                ];
            }
        } catch (Exception $e) {
            $folders[] = [
                'id'     => 'root',
                'name'   => 'Root Account',
                'parent' => null,
                'isDir'  => true,
            ];

            $this->log("Failed to build TikTok Ads tree: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return array_filter($folders, fn (array $folder) => (bool) data_get($folder, 'isDir'));
    }

    /** {package->hasFolders() && service->doesMetaContain()}::SyncFilesAndFoldersJob->index() */
    public function listFolderSubFolders(?array $request): array
    {
        $subfolders = [];

        /** @var null|string[] $folderIds */
        $folderIds = array_values(data_get($request, 'folder_ids', []));

        foreach ($folderIds as $advertiserId) {
            $subfolders[] = [
                'id'    => $advertiserId,
                'name'  => "Account (#{$advertiserId})",
                'isDir' => true,
            ];

            foreach ($this->ttService->fetchCampaignsForAdvertiser($advertiserId) as $campaign) {
                $subfolders[] = [
                    'id'    => $campaign->id,
                    'name'  => "Campaign {$campaign->name} (#{$campaign->id}:{$campaign->status})",
                    'isDir' => false,
                ];
            }
        }

        return $subfolders;
    }

    /**
     * Trace: SyncFilesAndFoldersJob->paginated() >> FileMassCreate->mutateAttributes().
     *
     * @param  mixed  $file  This is the array dispatched by $package->paginate().
     * @param  array  $attr  This is FileMassCreate::addExtraDataToAttributes().
     * @param  bool  $createThumbnail  Default FALSE
     */
    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        if (empty($file)) {
            return new FileDTO;
        }

        if (is_object($file) && ! is_array($file)) {
            $file = $file->toArray();
        }

        $baseFilename = data_get($file, 'file_name') ?? data_get($file, 'name');

        if (empty($baseFilename)) {
            $this->log('Skipping asset without file name', 'warn', null, compact('file', 'baseFilename'));

            return new FileDTO;
        }

        $filename = data_get($file, 'name');
        $extension = data_get($file, 'extension')
            ?? (data_get($file, 'type') === AssetType::Video->value ? 'mp4' : 'jpg');

        return new FileDTO([
            'id'                     => $this->uniqueFileId($file),
            'remote_service_file_id' => $this->uniqueFileId($file),
            'remote_page_identifier' => data_get($file, 'remote_page_identifier'),
            'user_id'                => data_get($attr, 'user_id') ?? $this->service->user->id,
            'team_id'                => data_get($attr, 'team_id') ?? $this->service->team->id,
            'service_id'             => data_get($attr, 'service_id') ?? $this->service->id,
            'service_name'           => data_get($attr, 'service_name') ?? $this->service->customName ?? $this->service->name,
            'import_group'           => data_get($file, 'import_group'),
            'size'                   => data_get($file, 'size'),
            'name'                   => $filename,
            'extension'              => $extension,
            'slug'                   => Str::slug($filename),
            'mime_type'              => $this->getMimeTypeOrExtension($extension),
            'type'                   => $this->getFileTypeFromExtension($extension) ?? data_get($file, 'type'),
            'thumbnail'              => $createThumbnail ? $this->getThumbnailPath($file) : data_get($file, 'thumbnail_url'),
            'created_time'           => Carbon::parse(data_get($file, 'created_at', 'now')),
            'modified_time'          => Carbon::parse(data_get($file, 'updated_at', 'now')),
        ]);
    }

    /**
     * Caller: FileMassCreate->prepareMetadataAttributes()
     * This method picks attributes off the array returned by paginate(),
     * and stores them as meta:extra on the File.
     * */
    public function getMetadataAttributes(?array $properties = []): array
    {
        return [
            'ttads_file_id' => data_get($properties, 'remote_service_file_id'),
            'advertiser_id' => data_get($properties, 'remote_page_identifier'),
            'signature'     => data_get($properties, 'signature'),
            'source_link'   => data_get($properties, 'source_link'),
            'thumbnail_url' => data_get($properties, 'thumbnail_url'),
        ];
    }

    /**
     * Caller: MariusCucuruz\DAMImporter\Jobs\Sync\CreateThumbnailsJob
     *
     * @param  array  $file  the array response from fileProperties()
     */
    public function getThumbnailPath(mixed $file = null): ?string
    {
        if (is_iterable($file) && filled(data_get($file, 'remote_service_file_id'))) {
            $file = File::query()
                ->where('remote_service_file_id', data_get($file, 'remote_service_file_id'))
                ->first();
        }

        if (empty($file)) {
            return null;
        }

        $thumbnailUrl = null;
        $storageThumbs = MediaStorageType::thumbnails;
        $filename = $this->prepareFileName(null, $storageThumbs, 'png');

        if (is_string($file)) {
            return $this->uploadFromUrlTo($file, $filename, $storageThumbs);
        }

        if ($file instanceof File && $metadata = $file->getMetaExtra()) {
            $thumbnailUrl = data_get($metadata, 'thumbnail_url') ?? data_get($file, 'thumbnail');
            $filename = $this->prepareFileName($file, $storageThumbs, 'png');
        }

        if (empty($thumbnailUrl)) {
            $this->log('No thumbnail URL found.', 'warn', null, compact('file'));

            return null;
        }

        return $this->uploadFromUrlTo($thumbnailUrl, $filename, $storageThumbs);
    }

    /** DownloadFileJob::File->download() */
    public function downloadTemporary(File $file, ?string $rendition = null): bool|string
    {
        $downloadUrl = $file->getMetaExtra('source_link');
        $saveAs = $this->prepareFileName($file);

        if (empty($downloadUrl) || empty($saveAs)) {
            return false;
        }

        return $this->uploadFromUrlTo($downloadUrl, $saveAs, MediaStorageType::originals) ?? false;
    }

    protected function uploadFromUrlTo(
        string $downloadUrl,
        ?string $filename = null,
        ?MediaStorageType $storage = MediaStorageType::thumbnails
    ): ?string {
        $downloadUrl = filter_var($downloadUrl, FILTER_VALIDATE_URL);
        $filename ??= str()->random(10) . '.png';

        if (empty($downloadUrl)) {
            $this->log('No thumbnail URL provided', 'warn');

            return null;
        }

        if (Str::startsWith($downloadUrl, [Path::forStorage('thumbnails'), Path::forStorage('originals')])) {
            $this->log("Path is already on S3: {$downloadUrl}", 'warn', null);

            return null;
        }

        try {
            $fileData = Http::timeout(30)
                ->connectTimeout(config('queue.timeout', 15))
                ->maxRedirects(10)
                ->get($downloadUrl)
                ->throw()
                ->getBody()
                ->getContents();
        } catch (Exception $e) {
            $this->log("Error downloading to tmp: {$e->getMessage()}", 'error', null, $e->getTrace());

            return null;
        }

        if (empty($fileData)) {
            $this->log("Downloading thumbnail received empty response: {$downloadUrl}", 'warn', null);

            return null;
        }

        return $this->storeDataAsFile($fileData, $filename, $storage->value);
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $downloadUrl = $file->getMetaExtra('source_link');
        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'File is missing source link.');

        $fileKey = $this->prepareFileName($file);
        throw_unless($fileKey, CouldNotDownloadFile::class, 'Cannot start multi-part upload: $fileKey missing');

        $uploadId = $this->createMultipartUpload($fileKey, $file->mime_type);
        throw_unless($uploadId, CouldNotDownloadFile::class, 'Failed to initialize multi-part upload');

        $chunkSize = config('manager.chunk_size', 5) * 1024 * 1024;
        $chunkStart = 0;
        $partNumber = 1;
        $parts = [];

        try {
            while (true) {
                $chunkEnd = $chunkStart + $chunkSize;

                $response = Http::timeout(config('queue.timeout', 15))
                    ->withHeaders(['Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd)])
                    ->get($downloadUrl)
                    ->throw();

                if ($response->status() !== Response::HTTP_PARTIAL_CONTENT) {
                    break;
                }

                $parts[] = $this->uploadPart($fileKey, $uploadId, $partNumber++, $response->body());

                $chunkStart = $chunkEnd + 1;
            }
        } catch (Exception $e) {
            if ($e->getCode() === Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE
                || ($e->getCode() === Response::HTTP_NOT_FOUND && $chunkStart > 0)
            ) {
                return $this->successfulMultiPartUpload($file, $fileKey, $uploadId, $parts);
            }

            $file->markFailure(
                FileOperationName::DOWNLOAD,
                'TikTokAds Multi-part download failed',
                $e->getMessage()
            );
            $this->log("Download multi-part failed: {$e->getMessage()}", 'error', null, $e->getTrace());

            return false;
        }

        return $this->successfulMultiPartUpload($file, $fileKey, $uploadId, $parts);
    }

    public function successfulMultiPartUpload(File $file, string $fileKey, string $uploadId, array $parts): string
    {
        $uploadKey = $this->completeMultipartUpload($fileKey, $uploadId, $parts);

        $file->update(['size' => $this->getFileSize($uploadKey)]);

        return $uploadKey;
    }

    /** FileController::downloadFromService() */
    public function downloadFromService(File $file): BinaryFileResponse|StreamedResponse|bool
    {
        $fileMeta = $file->getMetaExtra();
        $advertiserId = $file->remote_page_identifier ?? data_get($fileMeta, 'advertiser_id');
        throw_if(empty($advertiserId), CannotDownloadUrl::class, 'Cannot download asset from service');

        if ($file->type === AssetType::Video->value) {
            $originalFile = $this->ttService->streamVideo($file->remote_service_file_id, $advertiserId);
        }

        if ($file->type === AssetType::Image->value) {
            $originalFile = $this->ttService->streamImage($file->remote_service_file_id, $advertiserId);
        }

        throw_unless($originalFile, CannotDownloadUrl::class, 'Failed to download file from service');

        $filename = $file->name . '.' . $file->extension;
        $response = response()->streamDownload(fn () => print $originalFile->body(), $filename);
        $response->headers->set('Content-Type', $file->mime_type);
        $response->headers->set('Content-Disposition', "attachment; filename={$filename}");
        $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $response->headers->set('Pragma', 'public');

        return $response;
    }

    public function uniqueFileId($attribute, $key = 'remote_service_file_id'): mixed
    {
        return data_get($attribute, $key);
    }
}
