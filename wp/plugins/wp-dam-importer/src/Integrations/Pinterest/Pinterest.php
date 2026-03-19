<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Pinterest;

use Exception;
use Generator;
use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use MariusCucuruz\DAMImporter\DTOs\IntegrationDefinition;
use MariusCucuruz\DAMImporter\MariusCucuruz\DAMImporter\Integrations\IsIntegration;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\Exceptions\CannotDownloadUrl;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use Illuminate\Http\RedirectResponse;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Support\LazyCollection;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Http\Client\PendingRequest;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use Illuminate\Http\Client\ConnectionException;
use MariusCucuruz\DAMImporter\Enums\MediaStorageType;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Interfaces\HasRateLimit;
use MariusCucuruz\DAMImporter\Traits\ServiceRateLimiter;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;
use MariusCucuruz\DAMImporter\Exceptions\OAuthTokenRetrievalFailed;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class Pinterest extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata, HasRateLimit, HasSettings, IsIntegration, IsTestable
{
    use Loggable, ServiceRateLimiter;

    public const string DEFAULT_DATE_FORMAT = 'Y-m-d H:i:s';

    public ?string $clientId = null;

    public ?string $clientSecret = null;

    public ?string $accessToken = null;

    public ?string $refreshToken = null;

    protected string $baseUrl = 'https://api.pinterest.com/v5';

    protected ?int $limitPerRequest = null;

    public static function definition(): IntegrationDefinition
    {
        return IntegrationDefinition::make(
            name: self::getServiceName(),
            displayName: 'Pinterest',
            providerClass: PinterestServiceProvider::class,
            namespaceMap: [],
        );
    }

    public function initialize(): void
    {
        $this->startLog();

        $configs = config('pinterest');
        throw_if(empty($configs), new CouldNotInitializePackage('Failed to initialize: no config.'));

        $settings = $this->getSettings();

        $this->clientId = data_get($settings, 'PINTEREST_CLIENT_ID') ?? data_get($configs, 'clientId');
        throw_if(empty($this->clientId), InvalidSettingValue::make('Client Id'), 'Pinterest Client Id is missing!');

        $this->clientSecret = data_get($settings, 'PINTEREST_CLIENT_SECRET') ?? data_get($configs, 'clientSecret');
        throw_if(empty($this->clientSecret), InvalidSettingValue::make('Client Secret'), 'Pinterest Client Secret is missing!');
    }

    public function testSettings(Collection $settings): bool
    {
        $allSetting = config('pinterest.settings');
        abort_if($settings->isEmpty(), HttpResponse::HTTP_PRECONDITION_FAILED, 'Settings are required');

        $requiredSettings = array_filter($allSetting, fn (array $setting) => data_get($setting, 'required', false));
        abort_if(count($requiredSettings) > $settings->count(), HttpResponse::HTTP_EXPECTATION_FAILED, 'All Settings must be present');

        $clientId = (int) $settings->firstWhere('name', 'PINTEREST_CLIENT_ID')?->payload;
        abort_if(empty($clientId), HttpResponse::HTTP_UNPROCESSABLE_ENTITY, 'Client ID is invalid');

        $clientSecret = (string) $settings->firstWhere('name', 'PINTEREST_CLIENT_SECRET')?->payload;
        abort_if(empty($clientSecret), HttpResponse::HTTP_UNPROCESSABLE_ENTITY, 'Client Secret is invalid');

        return true;
    }

    public function redirectToAuthUrl($settings = null, ?string $email = null): RedirectResponse
    {
        $queryString = http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => config('pinterest.redirect_uri'),
            'scope'         => config('pinterest.scope'),
            'state'         => $this->generateRedirectOauthState(),
            'response_type' => 'code',
        ]);

        return $this->redirectTo("https://www.pinterest.com/oauth/?{$queryString}");
    }

    public function getTokens(?array $tokens = []): TokenDTO
    {
        try {
            $response = $this->http(null, true)
                ->post('https://api.pinterest.com/v5/oauth/token', array_filter([
                    'code'         => request('code'),
                    'redirect_uri' => config('pinterest.redirect_uri'),
                    'grant_type'   => 'authorization_code',
                ]));
        } catch (Exception $e) {
            $this->log("Token request failed {$e->getMessage()}", 'error', null, $e->getTrace());

            throw new OAuthTokenRetrievalFailed($e->getMessage());
        }

        $this->accessToken = data_get($response, 'access_token');
        $accessTokenExpireTtl = (int) data_get($response, 'expires_in');

        $this->refreshToken = data_get($response, 'refresh_token');
        $refreshTokenExpireTtl = (int) data_get($response, 'refresh_token_expires_in');

        return new TokenDTO([
            'access_token'             => $this->accessToken,
            'refresh_token'            => $this->refreshToken,
            'expires'                  => $accessTokenExpireTtl ? now()->addSeconds($accessTokenExpireTtl)->format(self::DEFAULT_DATE_FORMAT) : null,
            'created'                  => now()->format(self::DEFAULT_DATE_FORMAT),
            'refresh_token_ttl'        => $refreshTokenExpireTtl,
            'refresh_token_expires_at' => $refreshTokenExpireTtl ? now()->addSeconds($refreshTokenExpireTtl)->format(self::DEFAULT_DATE_FORMAT) : null,
            'refresh_token_updated_at' => now()->format(self::DEFAULT_DATE_FORMAT),
        ]);
    }

    private function refreshTokens(): self
    {
        try {
            $response = $this->http(null, true)
                ->post('https://api.pinterest.com/v5/oauth/token', [
                    'refresh_token' => $this->refreshToken ?? $this->service->refresh_token,
                    'scope'         => config('pinterest.scope'),
                    'grant_type'    => 'refresh_token',
                    'refresh_on'    => true,
                ]);

            $this->accessToken = data_get($response, 'access_token');
            $accessTokenExpireTtl = (int) data_get($response, 'expires_in');

            $this->refreshToken = data_get($response, 'refresh_token');
            $refreshTokenExpireTtl = (int) data_get($response, 'refresh_token_expires_in');

            $this->service->update([
                'access_token'             => $this->accessToken,
                'refresh_token'            => $this->refreshToken,
                'expires'                  => $accessTokenExpireTtl ? now()->addSeconds($accessTokenExpireTtl)->format(self::DEFAULT_DATE_FORMAT) : null,
                'refresh_token_ttl'        => $refreshTokenExpireTtl,
                'refresh_token_expires_at' => $refreshTokenExpireTtl ? now()->addSeconds($refreshTokenExpireTtl)->format(self::DEFAULT_DATE_FORMAT) : null,
                'refresh_token_updated_at' => now()->format(self::DEFAULT_DATE_FORMAT),
            ]);
        } catch (Exception $e) {
            $this->log("Token refresh failed {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return $this;
    }

    private function checkTokens(): self
    {
        if (empty($this->accessToken)) {
            if (Carbon::now()->isBefore($this->service->expires)) {
                $this->accessToken = $this->service->access_token;
                $this->refreshToken = $this->service->refresh_token;

                return $this;
            }

            if (Carbon::now()->isBefore($this->service->refresh_token_expires_at)) {
                return $this->refreshTokens();
            }
        }

        return $this;
    }

    public function getUser(): UserDTO
    {
        try {
            $response = $this->http($this->accessToken)
                ->get("{$this->baseUrl}/user_account");
        } catch (Exception $e) {
            $this->log("Error fetching user details: {$e->getMessage()}", 'error', null, $e->getTrace());

            throw new CouldNotGetToken($e->getMessage());
        }

        $userId = data_get($response, 'id');
        $userInfo = data_get($response, 'about');
        $business = data_get($response, 'business_name');
        $userImg = data_get($response, 'profile_image');
        $userName = data_get($response, 'username');

        return new UserDTO([
            'user_id' => $userId,
            'name'    => $userName,
            'email'   => "{$userId}@{$business}",
            'photo'   => $userImg,
        ]);
    }

    /**
     * List folders to choose which ones to sync (ServiceController::folder).
     */
    public function listFolderContent(?array $request): iterable
    {
        if ((string) data_get($request, 'folder_id') !== 'root') {
            return [];
            // return $this->getAssetsFromBoardName($folderId);
        }

        dd($this->fetchBoards());
        return $this->fetchBoards();
    }

    public function paginate(array $request = []): void
    {
        if ($reqBoardNames = (array) data_get($request, 'folder_ids')) {
            foreach ($reqBoardNames as $boardName) {
                LazyCollection::make(fn () => $this->getAssetsFromBoardName($boardName))
                    ->chunk($this->limitPerRequest)
                    ->each(fn (array $chunk) => $this->dispatch($chunk, $boardName));
            }

            return;
        }

        LazyCollection::make(fn () => $this->fetchAllBoardsPaginated())
            ->chunk($this->limitPerRequest)
            ->each(fn (array $items) => $this->dispatch($items, "pinterest-{$this->service->id}"));
    }

    /**
     * @deprecated
     *
     * @todo consider removing this method from IsSourcePackage interface as it is now defunct.
     */
    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $publicUrl = data_get($file, 'image_thumbnail_url') ?? data_get($file, 'image_cover_url');
        $originalsUrl = $this->parsePublicUrlToOriginalsUrl($publicUrl);

        if (empty($originalsUrl)) {
            return new FileDTO;
        }

        $fileName = pathinfo($originalsUrl, PATHINFO_BASENAME);
        $filetype = data_get($file, 'source', false) ? 'video' : 'image';

        return new FileDTO([
            'remote_service_file_id' => $this->uniqueFileId($file),
            'name'                   => $fileName,
            'thumbnail'              => $publicUrl,
            'user_id'                => $this->service?->user_id ?: data_get($attr, 'user_id'),
            'team_id'                => $this->service?->team_id ?: data_get($attr, 'team_id'),
            'service_id'             => $this->service?->id ?: data_get($attr, 'service_id'),
            'service_name'           => $this->service?->name ?: data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'mime_type'              => $this->getAttributeForFileType($filetype, 'mime_type'),
            'type'                   => $this->getAttributeForFileType($filetype, 'type'),
            'extension'              => $this->getAttributeForFileType($filetype, 'extension'),
            'duration'               => data_get($file, 'length'),
            'slug'                   => str()->slug($fileName),
            'created_time'           => Carbon::parse(data_get($file, 'created_time', 'now'))->format(self::DEFAULT_DATE_FORMAT),
            'modified_time'          => null,
        ]);
    }

    /**
     * @deprecated
     *
     * @todo consider removing this method from IsSourcePackage interface as it is now defunct.
     */
    public function getThumbnailPath(mixed $file): ?string
    {
        $thumbnailUrl = data_get($file, 'download_url') ?? data_get($file, 'image_cover_url');
        throw_if(empty($thumbnailUrl), CannotDownloadUrl::class);

        $saveAs = $this->prepareFileName(null, MediaStorageType::thumbnails);

        return $this->downloadFromUrlAndStoreAs($thumbnailUrl, $saveAs, MediaStorageType::thumbnails);
    }

    public function downloadTemporary(File $file, ?string $rendition = '720'): bool|string
    {
        $publicUrl = $this->getPublicUrl($file);
        throw_if(empty($publicUrl), CannotDownloadUrl::class);

        $fileOriginalsUrl = $this->parsePublicUrlToOriginalsUrl($publicUrl);
        throw_if(empty($fileOriginalsUrl), CannotDownloadUrl::class);

        $saveAs = $this->prepareFileName($file);

        $filePath = $this->downloadFromUrlAndStoreAs($fileOriginalsUrl, $saveAs);

        if (empty($filePath)) {
            $this->log("Error downloading original from {$fileOriginalsUrl}:", 'warning', null, compact('publicUrl', 'fileOriginalsUrl'));

            $filePath = $this->downloadFromUrlAndStoreAs($publicUrl, $saveAs);
        }

        throw_if(empty($filePath), CouldNotDownloadFile::class);

        if ($filesize = $this->getFileSize($filePath)) {
            $file->update([
                'thumbnail' => $filePath,
                'size'      => $filesize,
            ]);
        }

        return $filePath ?: false;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        $fileKey = $this->prepareFileName($file);
        $uploadId = $this->createMultipartUpload($fileKey, $file->mime_type);
        throw_unless($uploadId, CouldNotDownloadFile::class, 'Failed to initialize multi-part upload');

        $chunkSize = config('manager.chunk_size', 5) * 1024 * 1024;
        $chunkStart = 0;
        $partNumber = 0;
        $parts = [];

        try {
            while (true) {
                $chunkEnd = $chunkStart + $chunkSize;

                if (! $response = $this->downloadFile($file)) {
                    $this->log('Multi-part download failed', 'error', null, [$response, $file->toArray()]);

                    return false;
                }

                $parts[] = $this->uploadPart($fileKey, $uploadId, ++$partNumber, $response);
                $chunkStart = $chunkEnd + 1;
            }
        } catch (Exception $e) {
            $this->log("Multi-part download failed: {$e->getMessage()}", 'error', null, $e->getTrace());

            return false;
        } finally {
            $fileKey = $this->completeMultipartUpload($fileKey, $uploadId, $parts);
            $file->update(['size' => $this->getFileSize($fileKey)]);
        }

        return true;
    }

    public function downloadFromService(File $file): StreamedResponse|bool
    {
        $fileName = $this->prepareFileName($file);
        $chunkSizeBytes = config('manager.chunk_size', 5) * 1024 * 1024;
        $chunkStart = 0;

        try {
            $response = response()
                ->streamDownload(function () use (&$chunkStart, $chunkSizeBytes, $file, $fileName) {
                    while (true) {
                        $originalsUrl = $this->inferOriginalsUrlFromFile($file);
                        $chunkEnd = $chunkStart + $chunkSizeBytes;

                        try {
                            if (! $response = $this->fetch($originalsUrl)) {
                                break;
                            }

                            echo $response;
                            $chunkStart = $chunkEnd + 1;
                        } catch (Exception $e) {
                            if ($e->getResponse()) {
                                logger()->error($e->getMessage(), [$file, $e->getTrace()]);

                                continue;
                            }

                            logger()->info("Downloading {$fileName} from service completed.", $file->toArray());

                            break;
                        }
                    }
                }, $fileName);

            $response->headers->set('Content-Type', data_get($file, 'mime_type'));
            $response->headers->set('Content-Disposition', "attachment; filename={$fileName}");
            $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
            $response->headers->set('Pragma', 'public');
        } catch (Exception $e) {
            if ($e->getResponse()->getStatusCode() == 416) {
                logger()->error($e->getMessage(), [$file, $e->getTrace()]);
            }

            logger()->info("Successfully downloaded {$fileName} from service.", $file->toArray());
        }

        return $response ?? false;
    }

    public function getMetadataAttributes($properties = null): array
    {
        if (! empty($properties) && $properties instanceof FileDTO) {
            return $properties->toArray();
        }

        return $properties;
    }

    /**
     * Initially get it to `remote_service_file_id`, but try to:
     * - get the filename from `image_cover_url` when dealing with Json object, or
     * - get the filename from `download_url` when dealing with File object, or
     * - default to parent::uniqueFileId() when all else fails.
     *
     * @param  File|array|iterable  $attribute
     * @param  string  $key
     */
    public function uniqueFileId($attribute, $key = 'id'): string
    {
        $fileId = data_get($attribute, 'remote_service_file_id');

        if (is_array($attribute) && $imageUrl = data_get($attribute, 'image_cover_url')) {
            $fileId = pathinfo($imageUrl, PATHINFO_FILENAME);
        }

        if ($attribute instanceof File) {
            // when handling File model, overwrite identifier
            $publicUrl = data_get($attribute, 'download_url');

            if (! empty($publicUrl)) {
                $fileId = pathinfo($publicUrl, PATHINFO_FILENAME);
            }
        }

        return $fileId ?? parent::uniqueFileId($attribute, $key);
    }

    protected function fetchBoards(?string $reqBookmark = null): array
    {
        $queryString = http_build_query(array_filter([
            'page_size' => config('pinterest.limit_per_request', 50),
            'bookmark'  => $reqBookmark,
        ]));

        try {
            $getBoards = $this->http($this->accessToken ?? $this->service->access_token)
                ->get("{$this->baseUrl}/boards?{$queryString}");
        } catch (Exception $e) {
            $this->log("Failed fetching boards: {$e->getMessage()}.", 'error', null, $e->getTrace());

            return [];
        }

        return [
            ...(array) data_get($getBoards, 'items'),
            ...$this->fetchBoards(data_get($getBoards, 'bookmark')),
        ];
    }

    protected function fetchAllBoardsPaginated(?string $reqBookmark = null): Generator
    {
        $queryString = http_build_query(array_filter([
            'page_size' => config('pinterest.limit_per_request', 50),
            'bookmark'  => $reqBookmark,
        ]));

        $getBoards = $this->http($this->accessToken ?? $this->service->access_token)
            ->get("{$this->baseUrl}/boards?{$queryString}");

        foreach (data_get($getBoards, 'items', []) as $board) {
            yield $board;
        }

        if ($bookmark = data_get($getBoards, 'bookmark')) {
            $this->fetchAllBoardsPaginated((string) $bookmark);
        }
    }

    protected function getAssetsFromBoardName(string $boardName, int $retry = 0): array
    {
        $items = [];

        if ($retry >= 3) {
            $this->log("Cannot download assets from board after {$retry} tries.", 'warning', null, compact('boardName'));

            return $items;
        }

        try {
            $this->checkTokens();

            $boardPathName = Str::snake($boardName, '-');
            $fetchPins = $this->http($this->accessToken ?? $this->service->access_token)
                ->get("https://api.pinterest.com/v3/pidgets/boards/{$this->accountUsername()}/{$boardPathName}/pins/");

            foreach (data_get($fetchPins, 'data.pins', []) as $pin) {
                $assetUrl = collect(data_get($pin, 'images', []))->firstWhere('url')->value;
                dump(compact('pin'));

                if (empty($assetUrl)) {
                    continue;
                }

                $items[] = [
                    'id'              => pathinfo($assetUrl, PATHINFO_FILENAME),
                    'image_cover_url' => $assetUrl,
                    'source'          => (bool) data_get($pin, 'is_video', false),
                    'user_id'         => data_get($pin, 'pinner.id'),
                    'user_name'       => $this->accountUsername(),
                    'service_id'      => $this->service->id ?? $this->clientId,
                    'service_name'    => self::getServiceName(),
                    'board'           => $boardName,
                ];
            }
        } catch (Exception $e) {
            // just try again
            $this->log("Failed to fetch asset in board {$boardName}: {$e->getMessage()}", 'warnig', null, $e->getTrace());

            $this->refreshTokens();

            return $this->getAssetsFromBoardName($boardName, ++$retry);
        }

        $received = count($items);
        $expected = (int) data_get($fetchPins, 'data.board.pin_count', 0);

        if ($received !== $expected) {
            logger()->error("Assets count mismatch: expected {$expected} but got {$received} (board: {$boardName}).");
        }

        return $items;
    }

    protected function downloadFromUrlAndStoreAs(
        string $fileUrl,
        string $fileName,
        ?MediaStorageType $storage = MediaStorageType::originals
    ): ?string {
        try {
            $fileData = $this->fetch($fileUrl);
            $filePath = $this->storeDataAsFile($fileData, $fileName, $storage->value);

            throw_if(empty($filePath), new CannotDownloadUrl('Invalid file URL'));

            return $filePath;
        } catch (Exception $e) {
            $this->log("Error downloading: {$e->getMessage()}", 'error', null, [
                compact('fileUrl', 'fileName', 'storage'),
                $e->getTrace(),
            ]);
        }

        try {
            $tempFilePath = $this->downloadWithYtDlp($fileUrl);

            $filePath = $this->getStoragePath($fileName, $storage->value);

            $this->storage->put($filePath, fopen($tempFilePath, 'rb'));

            return $filePath;
        } catch (Exception $e) {
            $this->log("Error downloading: {$e->getMessage()}", 'error', null, [
                compact('fileUrl', 'fileName', 'storage'),
                $e->getTrace(),
            ]);
        }

        return null;
    }

    /**
     * Maps a mime type to an attribute.
     */
    protected function getAttributeForFileType(string $fileType, string $attribute): ?string
    {
        $mapAttrByFileType = [
            'video' => [
                'mime_type' => 'video/mp4',
                'type'      => 'video',
                'extension' => 'mp4',
            ],
            'image' => [
                'mime_type' => 'image/jpeg',
                'type'      => 'image',
                'extension' => 'jpg',
            ],
        ];

        return match ($attribute) {
            'mime_type' => data_get($mapAttrByFileType, "{$fileType}.mime_type"),
            'type'      => data_get($mapAttrByFileType, "{$fileType}.type"),
            'extension' => data_get($mapAttrByFileType, "{$fileType}.extension"),
            'duration'  => data_get($mapAttrByFileType, "{$fileType}.duration"),
            default     => null,
        };
    }

    /**
     * Attempt to get a publicly accessible URL, and construct the originals URL form it.
     *
     * @param  array|File  $file
     */
    protected function getPublicUrl(mixed $file): ?string
    {
        $publicUrl = data_get($file, 'download_url') ?? data_get($file, 'remote_service_file_id');

        // when $publicUrl is not a valid URL, construct it manually from file name
        if (! filter_var($publicUrl, FILTER_VALIDATE_URL, FILTER_NULL_ON_FAILURE)) {
            $fileName = data_get($file, 'name');

            if (empty($fileName)) {
                return null;
            }

            $publicUrl = sprintf('https://i.pinimg.com/originals/%s/%s/%s/%s',
                substr($fileName, 0, 2),
                substr($fileName, 2, 2),
                substr($fileName, 4, 2),
                $fileName
            );
        }

        $publicUrl = $this->parsePublicUrlToOriginalsUrl($publicUrl);

        return $publicUrl ?? null;
    }

    /**
     * Attempt to get an asset's originals URL from known URL patterns.
     * Default to given value, if it's a valid URL.
     *
     * So far I’ve only identified 3 digit sizes and resolutions, for instance:
     * - https://i.pinimg.com/400x300/6c/0c/96/6c0c96d4bd7b6c4df5fba6c39404a662.jpg
     * - https://i.pinimg.com/236x/36/77/6c/36776cbe31e8b15167a8b011a30f282a.jpg
     */
    protected function parsePublicUrlToOriginalsUrl(?string $publicUrl = null): ?string
    {
        if (empty($publicUrl) || filter_var($publicUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        if ($fileOriginalsUrl = preg_replace("/(\/(\d{3}x\d{3}|\d{3}x)\/)/", '/originals/', $publicUrl)) {
            return $fileOriginalsUrl;
        }

        return $publicUrl;
    }

    protected function accountUsername(): ?string
    {
        $accountOwner = explode('@', $this->service->email);
        $accountUsername = $accountOwner[0] ?? null;

        if (empty($accountUsername)) {
            throw new InvalidSettingValue("Could not determine account name holder ({$accountUsername}, {$this->service->email}).");
        }

        return $accountUsername;
    }

    protected function inferOriginalsUrlFromFile(File $file): ?string
    {
        $fileName = data_get($file, 'name')
            ?? data_get($file, 'slug')
            ?? data_get($file, 'remote_service_file_id');

        $fileExtension = data_get($file, 'extension')
            ?? $this->getFileExtensionFromFileName($fileName)
            ?? substr($fileName, -3);

        $downloadUrl = sprintf('https://i.pinimg.com/originals/%s/%s/%s/%s.%s',
            substr((string) $fileName, 0, 2),
            substr((string) $fileName, 2, 2),
            substr((string) $fileName, 4, 2),
            $fileName,
            $fileExtension,
        );

        if (! $downloadUrl || ! filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
            $downloadUrl = $this->parsePublicUrlToOriginalsUrl($this->getPublicUrl($file));
        }

        return $downloadUrl;
    }

    public function isServiceAuthorised(): bool
    {
        return filled($this->getUser()?->toArray());
    }

    private function http(?string $withBearerToken = null, ?bool $asForm = false): PendingRequest|Factory
    {
        $this->secondsToCooldown(60);

        $this->incrementAttempts(60);

        return Http::baseUrl($this->baseUrl)
            ->timeout(30)
            ->maxRedirects(10)
            ->withoutVerifying()
            ->acceptJson()
            ->when(empty($withBearerToken),
                fn (PendingRequest $request) => $request->withToken(base64_encode("{$this->clientId}:{$this->clientSecret}"), 'Basic'),
                fn (PendingRequest $request) => $request->withToken($withBearerToken, 'Bearer'),
            )
            ->when($asForm === true,
                fn (PendingRequest $request) => $request->asForm(),
                fn (PendingRequest $request) => $request->asJson(),
            )
            ->withUserAgent('Medialake Pinterest API Client/1.0')
            ->retry(
                times: 3,
                sleepMilliseconds: 750,
                when: function ($exception, PendingRequest $request) use ($withBearerToken) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if (filled($withBearerToken)) {
                        $this->checkTokens()
                            ->checkAndHandleServiceAuthorisation();
                    }

                    $request->when(empty($withBearerToken),
                        fn (PendingRequest $request) => $request->withToken(base64_encode("{$this->clientId}:{$this->clientSecret}"), 'Basic'),
                        fn (PendingRequest $request) => $request->withToken($withBearerToken, 'Bearer'),
                    );

                    return true;
                }
            )->throw();
    }

    private function fetch(?string $reqUri = null): ?string
    {
        if (empty($reqUri)) {
            return null;
        }

        try {
            return Http::timeout(300)
                ->maxRedirects(10)
                ->withoutVerifying()
                ->get($reqUri)
                ?->getBody()
                ->getContents();
        } catch (Exception $e) {
            $this->log("Fetch request failed: {$e->getMessage()}", 'warning', null, [compact('reqUri'), $e->getTrace()]);

            return null;
        }
    }
}
