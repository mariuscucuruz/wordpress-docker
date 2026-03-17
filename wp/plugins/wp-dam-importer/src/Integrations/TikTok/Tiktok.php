<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Tiktok;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use RuntimeException;
use GuzzleHttp\Client;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Support\Facades\Process;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotRefreshToken;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\MariusCucuruz\DAMImporter\Integrations\Concerns\ManagesOAuthTokens;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotQuery;
use Illuminate\Support\Facades\File as LaravelFile;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Interfaces\HasDateRangeFilter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;

class Tiktok extends SourceIntegration implements CanPaginate, HasDateRangeFilter, HasFolders, HasMetadata
{
    use ManagesOAuthTokens;

    public Client $client;

    private ?string $clientKey = null;

    private ?string $accessToken = '';

    private ?string $clientSecret = null;

    private ?string $redirectUri = null;

    public function isAccessTokenExpired(): bool
    {
        if (! $this->service) {
            return true;
        }

        return empty($this->service->access_token)
            || empty($this->service->expires)
            || Carbon::parse($this->service->expires)->isPast();
    }

    public function isRefreshTokenExpired(): bool
    {
        if (! $this->service) {
            return true;
        }

        if (empty($this->service->refresh_token_expires_at)) {
            return false;
        }

        return Carbon::parse($this->service->refresh_token_expires_at)->isPast();
    }

    protected function getClientCredentials(): array
    {
        return [
            'client_key'    => $this->clientKey,
            'client_secret' => $this->clientSecret,
        ];
    }

    protected function persistRefreshedTokens(array $response): void
    {
        $accessToken = data_get($response, 'access_token');
        $accessTokenTTL = data_get($response, 'expires_in');
        $refreshToken = data_get($response, 'refresh_token');
        $refreshTokenTTL = data_get($response, 'refresh_expires_in');

        $this->service->update([
            'access_token'             => $accessToken,
            'expires'                  => $accessTokenTTL ? now()->addSeconds($accessTokenTTL)->toDateTimeString() : null,
            'refresh_token'            => $refreshToken,
            'refresh_token_ttl'        => $refreshTokenTTL,
            'refresh_token_expires_at' => $refreshTokenTTL ? now()->addSeconds($refreshTokenTTL)->toDateTimeString() : null,
            'status'                   => IntegrationStatus::ACTIVE,
        ]);
    }

    public function initialize(): void
    {
        $settings = $this->getSettings();

        $this->clientKey = $settings['clientKey'];
        $this->clientSecret = $settings['clientSecret'];
        $this->redirectUri = $settings['redirectUri'];

        $this->handleTokenExpiration();
    }

    public function getSettings($customKeys = null): array
    {
        $settings = parent::getSettings($customKeys);

        $clientKey = $settings['TIKTOK_CLIENT_KEY'] ?? config('tiktok.client_key');
        $clientSecret = $settings['TIKTOK_CLIENT_SECRET'] ?? config('tiktok.client_secret');
        $redirectUri = config('tiktok.redirect_uri');

        return compact('clientKey', 'clientSecret', 'redirectUri');
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        try {
            $url = config('tiktok.oauth_base_url');
            $queryParams = [
                'client_key'    => $this->clientKey,
                'scope'         => 'user.info.basic,video.list',
                'redirect_uri'  => $this->redirectUri,
                'state'         => $this->generateRedirectOauthState(),
                'response_type' => 'code',
            ];

            throw_unless(
                $url && $this->clientKey && $this->redirectUri,
                CouldNotInitializePackage::class,
                'Tiktok settings are required!'
            );

            $queryString = http_build_query($queryParams);

            $requestUrl = "{$url}?{$queryString}";
            $this->redirectTo($requestUrl);
        } catch (CouldNotInitializePackage|CouldNotQuery|Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function http($hasBearer = true): PendingRequest
    {
        return Http::maxRedirects(10)
            ->timeout(config('queue.timeout'))
            ->withUserAgent('Medialake Tiktok API Client/1.0')
            ->asJson()
            ->retry(3, 750, function (Exception $e, PendingRequest $request) use ($hasBearer) {
                if ($hasBearer && $e->getCode() == 401) {
                    try {
                        $this->refreshAccessToken();
                        $this->log('Attempt to refresh Tiktok token after exception: ' . $e->getMessage());
                        $request->withToken($this->service->access_token);

                        return true;
                    } catch (CouldNotRefreshToken $refreshException) {
                        $this->log('Failed to refresh token: ' . $refreshException->getMessage(), 'error');

                        return false;
                    }
                }

                return false;
            });
    }

    public function getUser(): ?UserDTO
    {
        try {
            $response = $this->http()
                ->withToken($this->accessToken)
                ->accept('application/json')
                ->get(config('tiktok.query_base_url') . 'user/info/', [
                    'fields' => 'open_id,union_id,avatar_url,display_name',
                ])->throw();

            $body = json_decode($response->getBody()->getContents(), true);
            throw_unless($body, CouldNotGetToken::class, 'Invalid token response.');

            $id = data_get($body, 'data.user.open_id');
            $photo = null;

            if ($url = data_get($body, 'data.user.avatar_url')) {
                $photo = $this->storePhotoToS3($url, $id);
            }

            return new UserDTO([
                'email' => data_get($body, 'data.user.display_name'),
                'photo' => $photo,
                'id'    => $id,
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return new UserDTO;
        }
    }

    public function storePhotoToS3(?string $url = null, ?string $id = null): ?string
    {
        if (! $url || ! $id) {
            return null;
        }

        try {
            $response = $this->http(false)->get($url);

            if ($response->ok() && $photo = $response->getBody()->getContents()) {
                return $this->uploadThumbnail($photo);
            }

            throw new RuntimeException("Could not get photo from {$url}.");
        } catch (Exception $e) {
            $this->log("Failed to store to S3: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return null;
    }

    /**
     * @throws CouldNotGetToken
     */
    public function getTokens(array $tokens = []): TokenDTO
    {
        try {
            $response = $this->http(false)->withHeaders([
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Cache-Control' => 'no-cache',
            ])->asForm()->post(config('tiktok.query_base_url') . 'oauth/token/', [
                'client_key'    => $this->clientKey,
                'client_secret' => $this->clientSecret,
                'code'          => request('code'),
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->redirectUri,
            ]);

            throw_unless(
                $response->getStatusCode() == 200,
                CouldNotGetToken::class,
                'Failed to get access token.'
            );

            $body = json_decode($response->getBody()->getContents(), true);
            throw_unless($body, CouldNotGetToken::class, 'Invalid token response.');

            return new TokenDTO($this->storeToken($body));
        } catch (CouldNotGetToken|Throwable|Exception $e) {
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    /**
     * @throws Throwable
     */
    public function storeToken($body, bool $refresh = false): array
    {
        throw_unless(isset($body['access_token']), CouldNotGetToken::class, 'Invalid token response.');

        $this->accessToken = data_get($body, 'access_token');
        $accessTokenTTLInSeconds = data_get($body, 'expires_in');

        $refreshToken = data_get($body, 'refresh_token');
        $refreshTokenTTLInSeconds = data_get($body, 'refresh_expires_in');

        return [
            'access_token'             => $this->accessToken,
            'expires'                  => $accessTokenTTLInSeconds ? now()->addSeconds($accessTokenTTLInSeconds)->toDateTimeString() : null,
            'refresh_token'            => $refreshToken,
            'refresh_token_ttl'        => $refreshTokenTTLInSeconds, // 365 days ttl (seconds)
            'refresh_token_expires_at' => $refreshTokenTTLInSeconds ? now()->addSeconds($refreshTokenTTLInSeconds)->toDateTimeString() : null,
        ];
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        $url = $file->getMetaExtra('share_url');

        throw_unless($url, CouldNotDownloadFile::class, 'No Download URL found.');

        try {
            $tempFilePath = $this->downloadWithYtDlp($url);

            $path = Path::join(
                config('manager.directory.originals'),
                $file->id,
                $file->id . $file->extension
            );

            $this->storage->put($path, fopen($tempFilePath, 'rb'));

            return $path;
        } catch (Exception $e) {
            $this->log("Download failed: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return false;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $url = $file->getMetaExtra('share_url');

        if (empty($url)) {
            $this->log("Download URL Missing for file: {$file->id}");

            return false;
        }

        $storagePath = Path::join(config('manager.directory.originals'), $file->id);
        $fileName = "{$file->id}.{$file->extension}";
        $key = "{$storagePath}/{$fileName}";
        $uploadId = $this->createMultipartUpload($key, $file->mime_type);
        $chunkSize = config('manager.chunk_size', 5) * 1024 * 1024;
        $chunkStart = 0;
        $partNumber = 1;
        $parts = [];

        $tempFile = tmpfile();

        throw_unless($tempFile, CouldNotDownloadFile::class, 'Temporary file not found');

        try {
            $tempFilePath = $this->downloadWithYtDlp($url);

            $fileHandle = fopen($tempFilePath, 'r');

            while (! feof($fileHandle)) {
                $chunkData = fread($fileHandle, $chunkSize);
                $parts[] = $this->uploadPart($key, $uploadId, $partNumber++, $chunkData);

                $chunkStart += strlen($chunkData);
            }

            fclose($fileHandle);
            fclose($tempFile);

            return $this->completeMultipartUpload($key, $uploadId, $parts);
        } catch (Exception $e) {
            $this->log("Failed to download file: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return false;
    }

    public function downloadFromService(File $file): bool|StreamedResponse|BinaryFileResponse
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');
        $tempDir = storage_path('app/temp');
        LaravelFile::ensureDirectoryExists($tempDir);

        try {
            $url = $file->getMetaExtra('share_url');

            if (! $url) {
                $this->log("Download URL Missing for file: {$file->id}");

                return false;
            }

            $cmd = [
                config('tiktok.yt_dlp'),
                '--merge-output-format', 'mp4', '-o',
                $tempDir . DIRECTORY_SEPARATOR . '%(id)s.%(ext)s',
                $url,
            ];

            $process = Process::timeout(config('queue.timeout'))->run($cmd);

            throw_if($process->failed(), CouldNotDownloadFile::class, 'Failed to run the process');

            $response = response()
                ->download($tempDir . DIRECTORY_SEPARATOR . $file->remote_service_file_id . '.' . $file->extension, $file->slug . '.' . ($file->extension ?? 'mp4'))
                ->deleteFileAfterSend();

            $response->headers->set('Content-Type', $file->mime_type);
            $response->headers->set('Content-Disposition',
                'attachment; filename="' . $file->slug . '.' . $file->extension . '"');
            $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
            $response->headers->set('Pragma', 'public');

            return $response;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return false;
    }

    public function uploadThumbnail(mixed $file): ?string
    {
        $file = $file instanceof File ? $file : File::findOrFail($file['id']);

        $thumbnailUrl = $file->getMetaExtra('cover_image_url');

        if (empty($thumbnailUrl)) {
            return null;
        }

        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            $file->id,
            $file->id . '.jpg'
        );

        try {
            $body = $this->http(false)->get($thumbnailUrl)->body();
            $this->storage->put($thumbnailPath, $body);

            return $thumbnailPath;
        } catch (Exception $e) {
            $this->log("Failed to get thumbnail path: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return null;
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $title = data_get($file, 'title');
        $createTime = data_get($file, 'create_time')
            ? Carbon::parse(data_get($file, 'create_time'))->format('Y-m-d H:i:s')
            : null;
        $uniqueId = data_get($attr, 'service_name')
            . '-' . data_get($file, 'id')
            . '-' . data_get($attr, 'service_id');

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => data_get($file, 'id'),
            'created_time'           => $createTime,
            'modified_time'          => $createTime,
            'name'                   => $title ?: ($createTime ?: now()->format('Y-m-d H:i:s')),
            'mime_type'              => 'video/mp4',
            'type'                   => 'video',
            'extension'              => 'mp4',
            'duration'               => data_get($file, 'duration') ? data_get($file, 'duration') * 1000 : null,
            'slug'                   => $createTime ? str()->slug($createTime) : str()->slug($uniqueId),
        ]);
    }

    public function getMetadataAttributes(?array $properties): array
    {
        return collect(config('tiktok.metadata_fields'))
            ->mapWithKeys(fn ($value, $key) => [$value => data_get($properties, $key)])
            ->toArray() + $properties;
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = data_get($request, 'folder_id') ?? 'root';
        $folders = [];

        if ($folderId == 'root') {
            $folders[] = [
                'id'    => 'allvideos',
                'isDir' => true,
                'name'  => 'All Videos',
            ];
        }

        return $folders;
    }

    public function isServiceAuthorised(): bool
    {
        $response = Http::timeout(config('queue.timeout'))
            ->withToken($this->service->access_token)
            ->accept('application/json')
            ->post(config('tiktok.query_base_url') . 'video/list/?fields=id', [
                'max_count' => 1,
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

    public function paginate(?array $request = []): void
    {
        $this->handleTokenExpiration();
        $cursor = null;
        $page = 0;

        $folders = data_get($request, 'metadata') ?? [];

        if (empty($folders)) {
            $this->getAllFiles($cursor, $page);

            return;
        }

        array_walk($folders, function ($folder) use (&$cursor, &$page) {
            $id = data_get($folder, 'folder_id');
            $startDateInput = data_get($folder, 'start_time');
            $endDateInput = data_get($folder, 'end_time');

            if (! empty($startDateInput) && ! empty($endDateInput)) {
                $this->log("Invalid date range for folder ID: {$id}", 'error');

                continue;
            }

            if ($id == 'allvideos') {
                $this->getAllFiles($cursor, $page);
            }
        });
    }

    public function getAllFiles(?int $cursor, int $page): void
    {
        while (true) {
            $data = $this->getFilesByCursor($cursor);

            if (empty($data)) {
                break;
            }

            $filesList = data_get($data, 'data.videos', []);
            $hasMore = data_get($data, 'data.has_more', false);
            $cursor = data_get($data, 'data.cursor');

            if ($this->isDateSyncFilter) {
                $filesList = collect($filesList)
                    ->reject(fn ($file) => ! $this->isWithinDatePeriod(data_get($file, 'create_time')))
                    ->values()->toArray();
            }

            if (filled($filesList)) {
                $this->dispatch($filesList, ++$page);
            }

            if (! $hasMore || empty($cursor)) {
                break;
            }
        }
    }

    public function getFilesByCursor(?int $cursor = null): array
    {
        try {
            $response = $this->http()
                ->withToken($this->service->access_token)
                ->accept('application/json')
                ->post(config('tiktok.query_base_url') . 'video/list/?fields=' . config('tiktok.list_video_fields'), [
                    'max_count' => config('tiktok.per_page'),
                    'cursor'    => $cursor,
                ])->throw();

            return $response->json();
        } catch (Exception $e) {
            $this->checkAndHandleServiceAuthorisation();
            $this->log('Error listing files: ' . $e->getMessage(), 'error');

            return [];
        }
    }
}
