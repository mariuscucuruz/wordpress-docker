<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\LinkedIn;

use Exception;
use Generator;
use Carbon\Carbon;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use MariusCucuruz\DAMImporter\DTOs\IntegrationDefinition;
use MariusCucuruz\DAMImporter\MariusCucuruz\DAMImporter\Integrations\IsIntegration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Support\LazyCollection;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use Illuminate\Http\Client\ConnectionException;
use MariusCucuruz\DAMImporter\Enums\MediaStorageType;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Interfaces\HasRemoteServiceId;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class LinkedIn extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata, HasRemoteServiceId, HasSettings, IsIntegration, IsTestable
{
    public string $clientId;

    public string $clientSecret;

    public ?string $accessToken = null;

    public ?string $refreshToken = null;

    public ?string $redirectUri = null;

    public ?string $apiUrl = null;

    /** for caching, to minimise API requests */
    private ?array $userInfo = [];

    public static function definition(): IntegrationDefinition
    {
        return IntegrationDefinition::make(
            name: self::getServiceName(),
            displayName: 'LinkedIn',
            providerClass: LinkedInServiceProvider::class,
            namespaceMap: [],
        );
    }

    public function initialize(): static
    {
        $this->startLog();

        $settings = $this->getSettings() ?? [];
        $configs = self::loadConfiguration() ?: config('linkedin', []);
        throw_if(empty($configs), InvalidSettingValue::class, 'LinkedIn configuration is missing.');

        $this->apiUrl = config('linkedin.api_url');
        throw_if(empty($this->apiUrl), InvalidSettingValue::class, 'LinkedIn API URL is misconfigured.');

        $this->redirectUri = config('linkedin.redirect_uri');
        throw_if(empty($this->redirectUri), InvalidSettingValue::class, 'LinkedIn missing redirect URL.');

        $this->clientId = data_get($settings, 'LINKEDIN_CLIENT_ID') ?? data_get($configs, 'client_id');
        throw_if(empty($this->clientId), InvalidSettingValue::class, 'Invalid LinkedIn Client ID.');

        $this->clientSecret = data_get($settings, 'LINKEDIN_CLIENT_SECRET') ?? data_get($configs, 'client_secret');
        throw_if(empty($this->clientSecret), InvalidSettingValue::class, 'Invalid LinkedIn Client secret.');

        $this->accessToken ??= $this->service?->access_token;
        $this->refreshToken ??= $this->service?->refresh_token;

        return $this;
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $scopes = (array) config('linkedin.client_scopes', []);
        throw_unless($scopes, InvalidSettingValue::class, 'Invalid LinkedIn Client scopes.');

        $oauthUrl = config('linkedin.auth_url');
        throw_unless($oauthUrl, InvalidSettingValue::class, 'LinkedIn redirect URI is not configured.');

        $queryParams = http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'state'         => $this->generateRedirectOauthState(),
            'scope'         => implode(' ', $scopes),
            'redirect_uri'  => $this->redirectUri,
        ], '', '&', PHP_QUERY_RFC3986);

        $this->redirectTo("{$oauthUrl}/authorization?{$queryParams}");
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        $authUrl = config('linkedin.auth_url');
        throw_if(empty($authUrl), InvalidSettingValue::class, 'LinkedIn oAuth URL is not configured.');

        try {
            $fetchTokens = $this->http(withToken: false, withHeaders: false)
                ->asForm()
                ->post("{$authUrl}/accessToken", [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code'          => request('code'),
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $this->redirectUri,
                ])->json();
        } catch (Exception $e) {
            $this->log("{$e->getMessage()}", 'error', null, $e->getTrace());

            return new TokenDTO;
        }

        throw_if(empty($fetchTokens), CouldNotGetToken::class, 'Invalid token response.');

        return $this->updateTokens($fetchTokens);
    }

    public function getUser(): ?UserDTO
    {
        $userInfo = $this->fetchUserInfo();
        $userId = data_get($userInfo, 'sub') ?? data_get($userInfo, 'id');
        $userName = data_get($userInfo, 'name')
            ?? trim(data_get($userInfo, 'given_name') . ' ' . data_get($userInfo, 'family_name'));
        $userEmail = data_get($userInfo, 'email')
            ?? str($userName)->append("-{$userId}@linkedin.com")->slug('.')->toString();

        return new UserDTO([
            'user_id' => $userId,
            'email'   => $userEmail,
            'name'    => $userName,
            'photo'   => data_get($userInfo, 'picture'),
        ]);
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = data_get($request, 'folder_id');

        if (filled($folderId) && $folderId !== 'root') {
            return [];
        }

        $folders = [];

        foreach ($this->fetchPages() as $pageInfo) {
            $accID = data_get($pageInfo, 'id');
            $accName = data_get($pageInfo, 'localizedName') ?? data_get($pageInfo, 'vanityName');

            $folders[] = [
                'id'     => (string) $accID,
                'name'   => "Page: {$accName} ({$accID})",
                'parent' => null,
                'isDir'  => true,
            ];
        }

        return array_filter($folders);
    }

    public function isServiceAuthorised(): bool
    {
        return filled($this->fetchUserInfo());
    }

    public function checkAndHandleServiceAuthorisation(): void
    {
        if (! $this->isServiceAuthorised()) {
            $this->refreshAccessToken();
        }
    }

    public function paginate(array $request = []): void
    {
        $userDto = $this->getUser()?->toArray() ?? [];

        $importGroupName = implode('-', [
            'linkedin',
            data_get($userDto, 'user_id'),
            now()->toDateTimeString('minute'),
        ]);

        $selectedForSync = (array) data_get($request, 'folder_ids');

        if (count($selectedForSync) === 0) {
            $this->log('No folders selected to sync...', 'error');

            return;
        }

        foreach ($selectedForSync as $folderId) {
            LazyCollection::make(fn () => $this->fetchPostsForUrn("urn:li:organization:{$folderId}"))
                ->each(fn (array $post) => $this->dispatch($this->extractImagesFromPost($post), $importGroupName));
        }
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $fileUrn = (string) data_get($file, 'asset_id');
        $fileType = data_get($file, 'media_type') ?? $this->getTypeFromUrn($fileUrn);
        $fileName = data_get($file, 'title') ?? data_get($file, 'post_title');

        if (empty($fileName)) {
            $fileName = (string) data_get($file, 'post_id') ?: $fileUrn;
        }

        return new FileDTO([
            'remote_service_file_id' => $fileUrn,
            'remote_page_identifier' => data_get($file, 'author'),
            'type'                   => $fileType,
            'mime_type'              => $fileType,
            'extension'              => null,
            'slug'                   => str($fileName)->replace(':', '-')->slug()->limit(255)->toString(),
            'name'                   => str($fileName)->limit(150)->toString(),
            'modified_time'          => Carbon::make(data_get($file, 'modified_at')),
            'created_time'           => Carbon::make(data_get($file, 'created_at')),
            'user_id'                => data_get($attr, 'user_id') ?? $this->service->user_id,
            'team_id'                => data_get($attr, 'team_id') ?? $this->service->team_id,
            'service_id'             => data_get($attr, 'service_id') ?? $this->service->id,
            'service_name'           => data_get($attr, 'service_name') ?? $this->service->name,
            'import_group'           => data_get($attr, 'import_group'),
        ]);
    }

    public function getMetadataAttributes(?array $properties): array
    {
        return [
            'assetUrn'    => data_get($properties, 'asset_id'),
            'downloadUrl' => data_get($properties, 'asset_url'), // rarely present
            ...$properties,
        ];
    }

    public function getThumbnailPath(mixed $file): ?string
    {
        $assetUrn = data_get($file, 'remote_service_file_id') ?? data_get($file, 'asset_id');

        if (empty($assetUrn)) {
            $this->log('Cannot download thumbnail without URN', 'error', null, compact('file'));

            return null;
        }

        $getAsset = $this->downloadAssetByUrn($assetUrn);

        if (empty($getAsset)) {
            $this->log('Could not download thumbnail', 'error', null, compact('file'));

            return null;
        }

        $thumbnailPath = $this->prepareFileName(null, MediaStorageType::thumbnails);

        $this->storage->put($thumbnailPath, $getAsset->body());

        return $thumbnailPath;
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        $assetUrn = $file->remote_service_file_id ?? $file->getMetaExtra('assetUrn');
        $getAsset = $this->downloadAssetByUrn($assetUrn, $file);

        if (empty($getAsset)) {
            $this->log('Could not download asset', 'error');

            return false;
        }

        $filePath = $this->storeDataAsFile(
            fileData: $getAsset->body(),
            fileName: $this->prepareFileName(
                file: $file,
                storageType: MediaStorageType::originals,
                extension: $file->extension
                    ?? $this->getExtensionForType($this->getTypeFromUrn($assetUrn) ?? $file->type),
            ),
            storage: MediaStorageType::originals->value
        );

        if (empty($filePath)) {
            $this->log('Error downloading asset', 'error', null, $file->toArray());

            return false;
        }

        if ($fileSize = $this->getFileSize($filePath)) {
            $file->update(['size' => $fileSize]);
        }

        return $filePath;
    }

    public function downloadFromService(File $file): bool|StreamedResponse|BinaryFileResponse
    {
        $assetUrn = $file->remote_service_file_id ?? $file->getMetaExtra('assetUrn');
        $fileData = $this->downloadAssetByUrn($assetUrn, $file);

        if (empty($fileData)) {
            flash("Could not download {$file->type}", 'error');
            $this->log("Could not download {$file->type}", 'error', null, [$assetUrn => $file->toArray()]);

            return false;
        }

        try {
            $tempPath = $this->tmpFileResource();
            $tmpFile = fopen($tempPath, 'wb');

            fwrite($tmpFile, $fileData->body());
        } catch (Exception $e) {
            $this->log("Download from LinkedIn failed: {$e->getMessage()}", 'error', null, $e->getTrace());

            return false;
        }

        $response = new BinaryFileResponse($tempPath, 200, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
        ]);

        $file->refresh();

        $extension = $this->getExtensionForType($file->type ?? $this->getTypeFromUrn($file->remote_service_file_id));

        $filename = str($file->name ?? $file->remote_service_file_id)
            ->append('.')
            ->append($file->extension ?? $extension)
            ->trim()
            ->toString();

        $response->setContentDisposition('attachment', $filename)->deleteFileAfterSend();

        return $response;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        $assetUrn = $file->remote_service_file_id ?? $file->getMetaExtra('assetUrn');
        $assetType = $this->getTypeFromUrn($assetUrn) ?? $file->type;
        $extension = $file->extension ?? $this->getExtensionForType($assetType);
        $mimetype = $this->getMimeTypeOrExtension($extension) ?? $file->mime_type;

        $fileKey = $this->prepareFileName($file, MediaStorageType::originals, $extension);
        $uploadId = $this->createMultipartUpload($fileKey, $mimetype);
        throw_if(empty($uploadId), CouldNotDownloadFile::class, 'Could not set the key for multipart upload.');

        $downloadUrl = (string) $file->getMetaExtra('download_url');
        throw_if(empty($downloadUrl), CouldNotDownloadFile::class, 'Cannot download: no URL.');

        return $this->commonDownloadMultiPart($file, $downloadUrl, $fileKey, $uploadId);
    }

    public function commonDownloadMultiPart(File $file, string $downloadUrl, string $fileKey, string $uploadId): ?string
    {
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

                if ($response->status() !== HttpResponse::HTTP_PARTIAL_CONTENT) {
                    break;
                }

                if ($response->status() === HttpResponse::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE
                    || ($response->status() === HttpResponse::HTTP_NOT_FOUND && $chunkStart > 0)
                ) {
                    return $this->completeMultipartUpload($fileKey, $uploadId, $parts);
                }

                $parts[] = $this->uploadPart($fileKey, $uploadId, $partNumber++, $response->body());

                $chunkStart = $chunkEnd + 1;
            }
        } catch (Exception $e) {
            if ($e->getCode() === HttpResponse::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE
                || ($e->getCode() === HttpResponse::HTTP_NOT_FOUND && $chunkStart > 0)
            ) {
                return $this->completeMultipartUpload($fileKey, $uploadId, $parts);
            }

            return null;
        }

        return $this->completeMultipartUpload($fileKey, $uploadId, $parts);
    }

    public function recordDoesntExist(Service $service, File $file, mixed $attributes): bool
    {
        if (! data_get($attributes, 'remote_service_file_id')) {
            return true;
        }

        return $file::query()
            ->where('service_id', $service->id)
            ->where('remote_service_file_id', $this->uniqueFileId($attributes))
            ->where('service_name', 'linkedin')
            ->doesntExist();
    }

    public function uniqueFileId($attribute, $key = 'remote_service_file_id'): mixed
    {
        return data_get($attribute, $key);
    }

    public function getRemoteServiceId(): string
    {
        $userInfo = $this->fetchUserInfo();

        return data_get($userInfo, 'sub') ?? data_get($userInfo, 'id');
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), HttpResponse::HTTP_PRECONDITION_FAILED, 'required LinkedIn settings are missing');

        $allSetting = config('linkedin.settings');

        $requiredSettings = array_filter($allSetting, fn (array $setting) => str(data_get($setting, 'rules'))->contains('required'));
        abort_if(count($requiredSettings) > $settings->count(), HttpResponse::HTTP_EXPECTATION_FAILED, 'All Settings must be present');

        $this->initialize();

        return true;
    }

    private function refreshAccessToken(): void
    {
        if (empty($this->service?->refresh_token)) {
            $this->log('Cannot refresh token: empty token', 'error');

            return;
        }

        try {
            $response = $this->http(withToken: false, withHeaders: false)
                ->post("{$this->apiUrl}/oauth2/token", [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $this->service->refresh_token,
                    'grant_type'    => 'refresh_token',
                ])
                ->json('data');
        } catch (Exception $e) {
            $this->service?->update(['status' => IntegrationStatus::UNAUTHORIZED]);

            $this->log("Failed to refresh token: {$e->getMessage()}", 'error', null, $e->getTrace());

            return;
        }

        $this->updateTokens($response);
    }

    private function updateTokens(iterable $payload): ?TokenDTO
    {
        if (empty($payload)) {
            return null;
        }

        $accessToken = data_get($payload, 'access_token');
        $refreshToken = data_get($payload, 'refresh_token');

        if (empty($accessToken) && empty($refreshToken)) {
            return null;
        }

        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;

        $accessTokenExpireTtl = data_get($payload, 'expires_in') ?? 5184000; // 5184000 seconds (2 months)
        $accessTokenExpireAt = now()->addSeconds($accessTokenExpireTtl)->getTimestamp();

        $refreshTokenExpireTtl = data_get($payload, 'refresh_token_expires_in') ?? 31536000; // 31536000 seconds (365 days)
        $refreshTokenExpireAt = now()->addSeconds($refreshTokenExpireTtl)->getTimestamp();

        $this->service?->update([
            'access_token'             => $accessToken,
            'expires'                  => $accessTokenExpireAt,
            'refresh_token'            => $refreshToken,
            'refresh_token_ttl'        => $refreshTokenExpireTtl,
            'refresh_token_expires_at' => $refreshTokenExpireAt,
            'refresh_token_updated_at' => now(),
        ]);

        $this->service?->refresh();

        return new TokenDTO([
            'access_token'  => $this->accessToken,
            'expires'       => $accessTokenExpireAt,
            'expires_in'    => $accessTokenExpireTtl,
            'refresh_token' => $this->refreshToken,
        ]);
    }

    private function fetchUserInfo(): array
    {
        if (filled($this->userInfo)) {
            return $this->userInfo;
        }

        if ($this->service?->getMetaExtra('userinfo')) {
            return $this->userInfo = $this->service?->getMetaExtra('userinfo');
        }

        try {
            $this->userInfo = $this->http()->asJson()
                ->get("{$this->apiUrl}/v2/userinfo")
                ->json();

            $this->service?->setMetaExtra(['userinfo' => $this->userInfo]);
        } catch (Exception $e) {
            $this->log("Invalid user response: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return $this->userInfo ?? [];
    }

    private function fetchPages(): array
    {
        try {
            $response = $this->http()->asJson()
                ->get("{$this->apiUrl}/v2/organizationAcls", [
                    'q'          => 'roleAssignee',
                    'role'       => 'ADMINISTRATOR',
                    'state'      => 'APPROVED',
                    'projection' => '(elements*(*,organization~(*)))',
                ])->json();
        } catch (Exception $e) {
            $this->log("Failed to get pages from LinkedIn: {$e->getMessage()}", 'error', null, $e->getTrace());

            return [];
        }

        return array_filter((array) data_get($response, 'elements.*.organization~', []));
    }

    private function fetchPostsForUrn(string $authorUrn): Generator
    {
        $offset = 0;

        do {
            try {
                $response = $this->http()->asJson()
                    ->get("{$this->apiUrl}/rest/posts", [
                        'author'      => $authorUrn,
                        'q'           => 'author',
                        'count'       => 50,
                        'start'       => $offset,
                        'sortBy'      => 'LAST_MODIFIED',
                        'viewContext' => 'READER',
                    ])->json();
            } catch (Exception $e) {
                $this->log("Failed to get posts for {$authorUrn}: {$e->getMessage()}", 'error', null, $e->getTrace());

                return null;
            }

            if ($posts = data_get($response, 'elements')) {
                yield from $posts;
            }

            $offset += (count($posts) - 1);
            $hasMore = collect((array) data_get($response, 'paging.links'))
                ->pluck('href', 'rel')
                ->has('next');
        } while ($hasMore);

        return [];
    }

    private function extractImagesFromPost(array $postEntry): ?array
    {
        $postContent = (array) data_get($postEntry, 'content');

        $postData = [
            'post_id'         => data_get($postEntry, 'id'), // urn:li:share:5753501893573746696
            'post_quote'      => data_get($postEntry, 'commentary'),
            'post_title'      => data_get($postContent, 'article.title') ?? data_get($postContent, 'media.title'),
            'post_article'    => htmlentities(data_get($postContent, 'article.description') . "\n" . data_get($postContent, 'article.source')),
            'visibility'      => data_get($postEntry, 'visibility'), // "PUBLIC"
            'lifecycle_state' => data_get($postEntry, 'lifecycleState'), // "PUBLISHED"
            'modified_at'     => data_get($postEntry, 'lastModifiedAt'),
            'created_at'      => data_get($postEntry, 'publishedAt') ?? data_get($postEntry, 'createdAt'),
            'author'          => data_get($postEntry, 'author'), // urn:li:organization:592344
        ];

        $images = [];

        foreach ((array) data_get($postContent, 'multiImage.images', []) as $imageRef) {
            $images[] = $this->normalizeAssetPayload($imageRef, $postData);
        }

        if ($singleImage = data_get($postContent, 'media')) {
            $images[] = $this->normalizeAssetPayload((array) $singleImage, $postData);
        }

        if ($postArticle = data_get($postContent, 'article')) {
            $images[] = $this->normalizeAssetPayload((array) $postArticle, $postData);
        }

        return array_filter($images);
    }

    private function normalizeAssetPayload(array $asset, array $postData): ?array
    {
        if (empty($asset)) {
            return null;
        }

        $assetUrn = data_get($asset, 'thumbnail') ?? data_get($asset, 'id');

        return [
            ...$postData,
            'asset_id'    => $assetUrn,
            'media_type'  => data_get($asset, 'mediaType') ?? $this->getTypeFromUrn($assetUrn),
            'title'       => data_get($asset, 'altText') ?? data_get($asset, 'title') ?? data_get($postData, 'title'),
            'source'      => data_get($asset, 'source'),
            'description' => data_get($asset, 'description'),
            'created_at'  => data_get($asset, 'created.time') ?? data_get($asset, 'publishedAt'),
            'updated_at'  => data_get($asset, 'modified.time') ?? data_get($asset, 'lastModifiedAt'),
            'asset_url'   => data_get($asset, 'displaySize.downloadUrl')
                ?? data_get($asset, 'downloadUrl')
                ?? data_get($asset, 'thumbnails.0.downloadUrl'),
        ];
    }

    private function downloadAssetByUrn(?string $assetUrn, ?File $file = null): null|PromiseInterface|Response
    {
        $downloadUrl = $this->fetchNewSignedUrlForUrn($assetUrn);

        if (empty($downloadUrl)) {
            $this->log('Invalid URL while saving', 'error', null, compact('assetUrn', 'downloadUrl'));

            return null;
        }

        if ($file instanceof File) {
            $extension = $this->getFileExtensionFromRemoteUrl($downloadUrl) ?? $file->extension;

            $file->update([
                'extension' => ($extension === 'illustrator') ? 'pdf' : $extension,
                'mime_type' => $this->getMimeTypeOrExtension($extension) ?? $file->mime_type,
            ]);
        }

        try {
            return Http::withoutVerifying()
                ->timeout(60)
                ->accept('*/*')
                ->get($downloadUrl)
                ->throw();
        } catch (Exception $e) {
            $this->log("Failed to download and save {$assetUrn}: {$e->getMessage()}", 'error', null, $e->getTrace());

            return null;
        }
    }

    private function fetchNewSignedUrlForUrn(?string $assetUrn, ?bool $thumb = false): ?string
    {
        if (empty($assetUrn)) {
            return null;
        }

        $apiEndpoint = match ($this->getTypeFromUrn($assetUrn)) {
            'image'    => 'rest/images/' . urlencode($assetUrn),
            'video'    => 'rest/videos/' . urlencode($assetUrn),
            'document' => 'rest/documents/' . urlencode($assetUrn),
            'asset'    => 'rest/assets/' . urlencode($assetUrn),
            default    => null,
        };

        if (empty($apiEndpoint)) {
            return null;
        }

        try {
            $latestUrls = $this->http()->asJson()
                ->get("{$this->apiUrl}/{$apiEndpoint}")
                ->json();

            if (! $latestUrls) {
                $latestUrls = $this->http()->asJson()
                    ->get("{$this->apiUrl}/rest/assets/" . urlencode($assetUrn))
                    ->json();
            }
        } catch (Exception $e) {
            $this->log("Failed to resolve URL: {$e->getMessage()}", 'error', null, [$assetUrn, ...$e->getTrace()]);

            return null;
        }

        $downloadUrl = $thumb
            ? data_get($latestUrls, 'thumbnail')
            : data_get($latestUrls, 'downloadUrl');

        if (empty($downloadUrl) || ! filter_var((string) $downloadUrl, FILTER_VALIDATE_URL)) {
            // $this->log("Received Invalid URL: {$downloadUrl}", 'warning', null, compact('assetUrn', 'downloadUrl', 'latestUrls'));
            return null;
        }

        return $downloadUrl;
    }

    private function http(?bool $withToken = true, ?bool $withHeaders = true): PendingRequest
    {
        return Http::baseUrl($this->apiUrl)
            ->withUserAgent('Medialake LinkedIn API Client v0.1')
            ->when($withToken, fn (PendingRequest $q) => $q
                ->withToken($this->accessToken)
            )->when($withHeaders, fn (PendingRequest $q) => $q
            ->withHeaders([
                'Linkedin-Version' => config('linkedin.api_version') ?? '202510',
                'Connection'       => 'Keep-Alive',
            ])
            )
            ->timeout(config('queue.timeout', 30))
            ->maxRedirects(10)
            ->withoutVerifying()
            ->retry(
                times: 3,
                sleepMilliseconds: 750,
                when: function ($exception, PendingRequest $request) use ($withToken) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    $this->checkAndHandleServiceAuthorisation();

                    if ($withToken) {
                        $request->withToken($this->service?->access_token);
                    }

                    return true;
                }
            )->throw();
    }

    private function getTypeFromUrn(string $urn): ?string
    {
        $matchTypes = ['video', 'image', 'document'];

        return str($urn)
            ->chopStart('urn:li:')
            ?->explode(':')
            ?->first(fn ($val) => in_array(strval($val), $matchTypes));
    }

    private function getExtensionForType(string $fileType): ?string
    {
        return match ($fileType) {
            'video'    => 'mp4',
            'image'    => 'png',
            'document' => 'pdf',
            default    => 'jpeg',
        };
    }

    private function expandAllProjection(): string
    {
        return '(elements*(*))';
    }

    private function extendedProjection(): string
    {
        return '(elements*('
            . 'id,createdAt,lastModifiedAt,visibility,lifecycleState,'
            . 'commentary,'
            . 'author~(*),'
            . 'company~(*),'
            // . ',organization~(*)'
            // . 'author~(id,$URN,vanityName,localizedName,name,company~),'
            // . 'person~(*,company~)'
            // . 'data(com.linkedin.digitalmedia.mediaartifact.StillImage($URN,downloadUrl,displaySize)),'
            . 'content~('
                . 'media~(id,$URN,status,downloadUrl,title,displaySize,thumbnails*),'
                . 'multiImage~(images*(id,$URN,status,downloadUrl,title,displaySize))'
            . ')'
        . '))';
    }

    /**
     * Build comprehensive projection that decorates all nested image references
     * This eliminates the need for client-side extraction logic
     */
    private function fullProjection(): string
    {
        return '(elements*(' .
            'id,author,createdAt,lastModifiedAt,' .
            'commentary,visibility,lifecycleState,' .
            'author~(id,vanityName,localizedName),' .
            'content,' .
            'content~(' .
                // Decorate single media with full asset details
                'media~(' .
                    'id,status,mediaType,title,created,' .
                    'recipes*(recipe,downloadUrl),' .
                    'displaySize(downloadUrl),' .
                    'thumbnails*(downloadUrl)' .
                '),' .
                // Decorate multi-image carousel
                'multiImage~(images*(' .
                    'id,' .
                    'digitalmediaAsset~(' .
                        'id,status,mediaType,title,created,' .
                        'recipes*(recipe,downloadUrl),' .
                        'displaySize(downloadUrl),' .
                        'thumbnails*(downloadUrl)' .
                    ')' .
                ')),' .
                // Decorate article thumbnails
                'article~(thumbnail~(' .
                    'id,status,mediaType,title,created,' .
                    'recipes*(recipe,downloadUrl),' .
                    'displaySize(downloadUrl),' .
                    'thumbnails*(downloadUrl)' .
                '))' .
            ')' .
        '))';
    }
}
