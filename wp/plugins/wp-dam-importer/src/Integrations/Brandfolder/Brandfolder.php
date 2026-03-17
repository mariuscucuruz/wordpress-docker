<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Brandfolder;

use Exception;
use Generator;
use Throwable;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\RequestException;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Client\ConnectionException;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\DTOs\IntegrationDefinition;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasRateLimit;
use MariusCucuruz\DAMImporter\Traits\ServiceRateLimiter;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use MariusCucuruz\DAMImporter\Integrations\Brandfolder\Schema\BrandfolderSchema;
use MariusCucuruz\DAMImporter\Interfaces\HasRemoteServiceId;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\SourcePackageManager;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class Brandfolder extends SourcePackageManager implements CanPaginate, HasFolders, HasMetadata, HasRateLimit, HasRemoteServiceId
{
    use ServiceRateLimiter;

    public ?string $apiKey = null;

    public ?string $apiUrl = null;

    /** for caching, to minimise API requests */
    private ?array $userInfo = [];

    public static function definition(): IntegrationDefinition
    {
        return IntegrationDefinition::make(
            name: 'brandfolder',
            displayName: 'Brandfolder',
            providerClass: BrandfolderServiceProvider::class,
            namespaceMap: [],
        );
    }

    public function initialize(): static
    {
        $configuration = self::loadConfiguration();
        $this->apiUrl = data_get($configuration, 'api_url') . '/' . data_get($configuration, 'api_version');

        throw_if(empty($this->apiUrl), InvalidSettingValue::class, 'Brandfolder API URL is misconfigured.');

        $this->apiKey = $this->service?->api_key
            ?? $this->getTokens()->api_key;

        throw_if(empty($this->apiKey), InvalidSettingValue::class, 'Brandfolder API Key misconfigured.');

        return $this;
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $state = $this->generateRedirectOauthState();

        $redirectUri = config('brandfolder.redirect_uri');

        if (empty($redirectUri)) {
            abort(500, 'Brandfolder redirect URI is not configured.');
        }

        $authUrl = $redirectUri . '?' . http_build_query(compact('state'));

        $this->redirectTo($authUrl);
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        $apiKey = data_get($this->getSettings(), 'BRANDFOLDER_API_KEY');
        throw_if(empty($apiKey), CouldNotGetToken::class, 'Brandfolder API key is required.');

        return new TokenDTO([
            'api_key' => $apiKey,
            'expires' => null,
            'created' => now(),
        ]);
    }

    public function getUser(): ?UserDTO
    {
        $userInfo = $this->fetchUserInfo();

        throw_if(empty($userInfo), CouldNotGetToken::class, 'Brandfolder API could not provide user info.');

        return new UserDTO([
            'user_id' => data_get($userInfo, 'id'),
            'name'    => trim(data_get($userInfo, 'attributes.first_name') . ' ' . data_get($userInfo, 'attributes.last_name')),
            'email'   => data_get($userInfo, 'attributes.email') ?? auth()->user()?->email,
            'photo'   => auth()->user()?->profile_photo_url ?? auth()->user()?->getOriginal('profile_photo_path'),
        ]);
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = data_get($request, 'folder_id');

        if (filled($folderId) && $folderId !== 'root') {
            return []; // no subfolders
        }

        $folders = [];

        foreach ($this->getFolders() as $pageInfo) {
            $accName = data_get($pageInfo, 'attributes.name');

            $folders[] = [
                'id'    => (string) data_get($pageInfo, 'id'),
                'name'  => $accName,
                'isDir' => true,
            ];
        }

        return array_filter($folders);
    }

    public function getFolders(): Generator
    {
        $page = (int) config('brandfolder.pagination_start', 1);
        $per = (int) config('brandfolder.page_size', 100);

        try {
            do {
                $response = $this->http()->asJson()
                    ->get("{$this->apiUrl}/brandfolders", [
                        'page' => $page,
                        'per'  => $per,
                    ]);

                $data = $response->json('data', []);

                foreach ($data as $folder) {
                    yield $folder;
                }

                $meta = $response->json('meta', []);
                $current = (int) data_get($meta, 'current_page', $page);
                $totalPages = (int) data_get($meta, 'total_pages', $current);

                $page = $current + 1;
            } while ($current < $totalPages);
        } catch (Throwable $e) {
            $this->log('Brandfolder getFolders failed: ' . $e->getMessage(), 'error');
        }
    }

    public function paginate(array $request = []): void
    {
        $folders = data_get($request, 'metadata', []);

        if (! is_array($folders) || empty($folders)) {
            return;
        }

        foreach ($folders as $folder) {
            $folderId = trim(data_get($folder, 'folder_id', ''));

            if (empty($folderId)) {
                continue;
            }

            $this->paginateAndDispatchFolderAssets($folderId);
        }
    }

    public function paginateAndDispatchFolderAssets(string $folderId): void
    {
        $page = (int) config('brandfolder.pagination_start', 1);

        try {
            do {
                $rawResponse = $this->retrievePage($folderId, $page);

                if (empty($rawResponse)) {
                    break;
                }

                $response = $this->mutateResponse($folderId, $rawResponse);
                $filteredAssets = $this->filterSupportedFileExtensions($response, 'attributes.attachment.extension');

                if (! empty($filteredAssets)) {
                    $this->dispatch($filteredAssets, $page);
                }

                $nextPage = data_get($rawResponse, 'meta.next_page');

                if (! is_null($nextPage) && (int) $nextPage <= $page) {
                    break;
                }

                $page = (int) $nextPage;
            } while (! is_null($nextPage));
        } catch (Throwable $e) {
            $this->log('Brandfolder paginateFolder failed: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * @throws ConnectionException
     */
    public function retrievePage(string $folderId, int $index): array
    {
        $per = (int) config('brandfolder.page_size', 100);

        return $this->http()->asJson()
            ->get("{$this->apiUrl}/brandfolders/{$folderId}/assets", [
                'include' => 'attachments',
                'per'     => $per,
                'page'    => $index,
                'fields'  => implode(',', BrandfolderSchema::ASSET_FIELDS),
            ])
            ->json();
    }

    private function mutateResponse(string $folderId, array $response): array
    {
        $data = (array) data_get($response, 'data', []);
        $included = (array) data_get($response, 'included', []);

        $attachmentsById = [];

        foreach ($included as $item) {
            if (data_get($item, 'type') !== 'attachments') {
                continue;
            }

            $id = (string) data_get($item, 'id');

            if (filled($id)) {
                $attachmentsById[$id] = $item;
            }
        }

        $flattened = [];
        $seen = [];

        foreach ($data as $asset) {
            $assetId = (string) data_get($asset, 'id');

            if (empty($assetId)) {
                continue;
            }

            $assetAttrs = (array) data_get($asset, 'attributes', []);
            $attachmentRefs = (array) data_get($asset, 'relationships.attachments.data', []);

            foreach ($attachmentRefs as $ref) {
                $attachmentId = (string) data_get($ref, 'id');

                if (empty($attachmentId)) {
                    continue;
                }

                $compositeId = "{$assetId}:{$attachmentId}";

                if (isset($seen[$compositeId])) {
                    continue;
                }
                $seen[$compositeId] = true;

                $attachment = $attachmentsById[$attachmentId]
                    ?? ['id' => $attachmentId, 'type' => 'attachments', 'attributes' => []];

                $attachmentAttrs = (array) data_get($attachment, 'attributes', []);

                $flattened[] = [
                    'id'         => $compositeId,
                    'type'       => 'attachments',
                    'attributes' => [
                        'folder_id'     => $folderId,
                        'asset_id'      => $assetId,
                        'attachment_id' => $attachmentId,

                        'asset'      => $assetAttrs,
                        'attachment' => $attachmentAttrs,
                    ],
                ];
            }
        }

        return $flattened;
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $fileId = data_get($file, 'id');

        if (empty($fileId)) {
            return new FileDTO;
        }

        $fileExtension = data_get($file, 'attributes.attachment.extension');
        $fileMimeType = data_get($file, 'attributes.attachment.mimetype');
        $fileName = data_get($file, 'attributes.attachment.filename') ?? $fileId;
        $fileResolution = (data_get($file, 'attributes.attachment.width') && data_get($file, 'attributes.attachment.height'))
            ? data_get($file, 'attributes.attachment.width') . 'x' . data_get($file, 'attributes.attachment.height')
            : null;

        return new FileDTO([
            'remote_service_file_id' => $fileId,
            'extension'              => $fileExtension ?? $this->getMimeTypeOrExtension($fileMimeType),
            'mime_type'              => $fileMimeType ?? $this->getMimeTypeOrExtension($fileExtension),
            'type'                   => $this->getFileTypeFromExtension($fileExtension),
            'slug'                   => str($fileName)->slug()->toString(),
            'name'                   => $fileName,
            'size'                   => data_get($file, 'attributes.attachment.size'),
            'resolution'             => $fileResolution,
            'created_time'           => Carbon::make(data_get($file, 'attributes.asset.created_at', 'now'))->toString(),
            'modified_time'          => Carbon::make(data_get($file, 'attributes.asset.updated_at', 'now'))->toString(),
            'user_id'                => data_get($attr, 'user_id') ?? $this->service->user_id,
            'team_id'                => data_get($attr, 'team_id') ?? $this->service->team_id,
            'service_id'             => data_get($attr, 'service_id') ?? $this->service->id,
            'service_name'           => data_get($attr, 'service_name') ?? $this->service->name,
            'import_group'           => data_get($attr, 'import_group'),
        ]);
    }

    /**
     * @throws ConnectionException|RequestException
     */
    public function getThumbnailPath(mixed $file = null, $source = null): string
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
        $cdnUrl = $file instanceof File
            ? $file->getMetaExtra('attributes.attachment.thumbnail_url')
            : data_get($file, 'attributes.attachment.thumbnail_url');

        if ($this->isUrlValid($cdnUrl)) {
            return $cdnUrl;
        }

        $remoteServiceFileId = $file instanceof File
            ? $file->remote_service_file_id
            : data_get($file, 'remote_service_file_id');

        $attachmentId = $this->splitCompositeId($remoteServiceFileId);
        throw_if(empty($attachmentId), Exception::class, 'Cannot resolve attachment ID from remote service file ID.');

        $attachmentResponse = $this->getFileAttachment($attachmentId);

        $freshThumbnailUrl = data_get($attachmentResponse, 'attributes.thumbnail_url');

        return $this->isUrlValid($freshThumbnailUrl) ? $freshThumbnailUrl : null;
    }

    private function isUrlValid(?string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        try {
            $res = Http::timeout(10)
                ->retry(2, 200)
                ->withHeaders(['Accept' => '*/*'])
                ->head($url);

            if ($res->status() === 405) {
                $res = Http::timeout(10)
                    ->retry(2, 200)
                    ->withHeaders([
                        'Accept' => '*/*',
                        'Range'  => 'bytes=0-0',
                    ])
                    ->get($url);
            }

            return ($res->status() >= 200 && $res->status() < 400)
                || $res->status() === 206;
        } catch (Throwable) {
            return false;
        }
    }

    public function getFileAttachment(?string $attachmentId): ?array
    {
        if (empty($attachmentId)) {
            return null;
        }

        try {
            $response = $this->http()->asJson()
                ->get("{$this->apiUrl}/attachments/{$attachmentId}", [
                    'fields' => implode(',', BrandfolderSchema::ATTACHMENT_FIELDS),
                ]);

            return $response->json('data');
        } catch (Throwable $e) {
            $this->log("Brandfolder getAttachment failed (attachment {$attachmentId}): {$e->getMessage()}", 'error');

            return null;
        }
    }

    /**
     * @throws Throwable
     */
    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        $downloadUrl = $this->getUrl($file);

        throw_unless(
            $downloadUrl,
            CouldNotDownloadFile::class,
            "Download URL not provided. File ID: {$file->id}"
        );

        return $this->handleTemporaryDownload($file, $downloadUrl);
    }

    /**
     * @throws Throwable
     */
    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        $downloadUrl = $this->getUrl($file);

        try {
            $tempPath = $this->streamServiceFileToTempFile($downloadUrl);

            $filename = trim(
                ($file->name ?: (string) $file->id) . '.' . ltrim((string) $file->extension, '.'),
                '.'
            );

            $response = new BinaryFileResponse($tempPath, 200, [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            ]);

            $response->setContentDisposition('attachment', $filename);
            $response->deleteFileAfterSend();

            return $response;
        } catch (Throwable $e) {
            $this->log("Error downloading file. Error: {$e->getMessage()}", 'error');

            return false;
        }
    }

    /**
     * @throws Throwable
     */
    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        $downloadUrl = $this->getUrl($file);

        throw_unless(
            $downloadUrl,
            CouldNotDownloadFile::class,
            "Download URL not provided. File ID: {$file->id}"
        );

        return $this->handleTemporaryDownload($file, $downloadUrl);
    }

    public function getRemoteServiceId(): string
    {
        $userInfo = $this->fetchUserInfo();

        return (string) data_get($userInfo, 'id');
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), Response::HTTP_PRECONDITION_FAILED, 'Missing required Brandfolder settings.');

        $allSetting = config('brandfolder.settings');

        $requiredSettings = array_filter($allSetting, fn (array $setting) => str(data_get($setting, 'rules'))->contains('required'));
        abort_if(count($requiredSettings) > $settings->count(), Response::HTTP_EXPECTATION_FAILED, 'All Settings must be present');

        return true;
    }

    private function fetchUserInfo(): array
    {
        if (filled($this->userInfo)) {
            return $this->userInfo;
        }

        try {
            $this->userInfo = $this->http()->asJson()
                ->get("{$this->apiUrl}/users/whoami")
                ->json('data');
        } catch (Exception $e) {
            $this->log("Invalid user response: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return $this->userInfo ?? [];
    }

    private function http($withAccessToken = true): PendingRequest
    {
        $request = Http::baseUrl($this->apiUrl)
            ->timeout(60)
            ->maxRedirects(10)
            ->retry(times: 3, sleepMilliseconds: 750)
            ->throw();

        if ($withAccessToken && filled($this->apiKey)) {
            $request->withToken($this->apiKey);
        }

        return $request;
    }

    private function splitCompositeId(?string $remoteServiceFileId): ?string
    {
        if (empty($remoteServiceFileId)) {
            return null;
        }

        [$assetId, $attachmentId] = array_pad(explode(':', $remoteServiceFileId, 2), 2, null);

        return $attachmentId;
    }

    /**
     * @return array|mixed|string|null
     *
     * @throws Throwable
     */
    public function getUrl(File $file): mixed
    {
        $originalUrl = $file->getMetaExtra('attributes.attachment.url');

        if ($this->isUrlValid($originalUrl)) {
            $downloadUrl = $originalUrl;
        } else {
            throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

            $attachmentId = $this->splitCompositeId($file->remote_service_file_id);
            $attachmentResponse = $this->getFileAttachment($attachmentId);

            $downloadUrl = data_get($attachmentResponse, 'attributes.url');
        }

        throw_unless($this->isUrlValid($downloadUrl), CouldNotDownloadFile::class, 'No valid download URL available.');

        return $downloadUrl;
    }
}
