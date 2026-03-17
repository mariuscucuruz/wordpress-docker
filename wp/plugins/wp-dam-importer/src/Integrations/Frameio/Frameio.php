<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Http\Response;
use Illuminate\Support\Sleep;
use Illuminate\Support\Carbon;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\DTOs\IntegrationDefinition;
use MariusCucuruz\DAMImporter\MariusCucuruz\DAMImporter\Integrations\IsIntegration;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use MariusCucuruz\DAMImporter\DTOs\CommentDTO;
use MariusCucuruz\DAMImporter\DTOs\ServiceDTO;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\Interfaces\HasIndex;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\Interfaces\HasComments;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Interfaces\HasVersions;
use MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\FrameioApp;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Frameio as FrameioSDK;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;

class Frameio extends SourceIntegration implements HasComments, HasFolders, HasIndex, HasMetadata, HasSettings, HasVersions, IsIntegration, IsTestable
{
    private ?string $accessToken;

    private ?string $callbackUrl;

    private ?string $scope;

    public static function definition(): IntegrationDefinition
    {
        return IntegrationDefinition::make(
            name: self::getServiceName(),
            displayName: 'Frameio',
            providerClass: FrameioServiceProvider::class,
            namespaceMap: [],
        );
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $this->scope = config('frameio.scope');
        $this->callbackUrl = $this->getSettings()['redirectUri'];

        throw_unless(
            $this->scope && $this->callbackUrl,
            CouldNotInitializePackage::class,
            'Scope or Callback URL cannot be empty'
        );

        $this->settings = $settings;

        $frameio = $this->initialize();

        $authHelper = $frameio->getAuthHelper();

        $params = [
            'state' => json_encode(['settings' => $this->settings?->pluck('id')?->toArray()]),
        ];

        $this->redirectTo($authHelper->getAuthUrl($this->callbackUrl, $params, $this->scope));
    }

    public function getSettings($customKeys = null): array
    {
        $this->scope = config('frameio.scope');
        $settings = parent::getSettings($customKeys);

        $clientId = $settings['FRAMEIO_CLIENT_ID'] ?? config('frameio.client_id');
        $clientSecret = $settings['FRAMEIO_CLIENT_SECRET'] ?? config('frameio.client_secret');

        $redirectUri = config('frameio.redirect_uri');

        return compact('clientId', 'clientSecret', 'redirectUri');
    }

    public function initialize(?string $accessToken = null): FrameioSDK
    {
        $settings = $this->getSettings();
        $frameioApp = new FrameioApp($settings['clientId'], $settings['clientSecret'], $accessToken);

        return new FrameioSDK($frameioApp);
    }

    public function getUser(): ?UserDTO
    {
        try {
            $frameio = $this->initialize($this->accessToken);
            $account = (object) $frameio->getCurrentAccount();

            if (! empty($profilePhoto = $account->getProfilePhotoUrl())) {
                $name = strtolower(str()->random(10)) . '.jpg';

                $ch = curl_init($profilePhoto);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                $contents = curl_exec($ch);
                curl_close($ch);

                $filename = Path::join(self::getServiceName(), $name);
                $this->storage->put($filename, $contents);
                $photoUrl = $filename;
            } else {
                $photoUrl = null;
            }

            return new UserDTO([
                'photo'     => $photoUrl,
                'email'     => $account->getEmail(),
                'accountId' => $account->getAccountId(),
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return new UserDTO;
    }

    public function fileProperties($file, array $attr = [], bool $createThumbnail = true): FileDTO
    {
        if (empty($file)) {
            $this->log('File is empty', 'error');

            return new FileDTO;
        }

        return new FileDTO([
            'parent_id'              => data_get($attr, 'parent_id'),
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => data_get($file, 'id'),
            'name'                   => pathinfo($file['name'], PATHINFO_FILENAME) ?? null,
            'thumbnail'              => data_get($file, 'thumb'),
            'mime_type'              => data_get($file, 'filetype'),
            'type'                   => dirname(data_get($file, 'filetype', '')) ?? null,
            'extension'              => $this->getMimeTypeOrExtension($file['filetype']) ?? null,
            'resolution'             => isset($file['transcodes']['original_width'], $file['transcodes']['original_height'])
                ? "{$file['transcodes']['original_width']}x{$file['transcodes']['original_height']}"
                : null,
            'size'          => data_get($file, 'filesize'),
            'duration'      => isset($file['duration']) ? ((int) ($file['duration']) * 1000) : null,
            'fps'           => data_get($file, 'fps'),
            'slug'          => str()->slug(pathinfo($file['name'], PATHINFO_FILENAME)) ?? null,
            'modified_time' => isset($file['updated_at'])
                ? Carbon::parse($file['updated_at'])->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public function saveVersion(File $file, array $attributes = []): bool
    {
        if (! isset($file->id, $attributes['type'], $attributes['id']) || $attributes['type'] !== 'version_stack') {
            return false;
        }

        $frameio = $this->initialize($this->getAccessToken());
        $children = $frameio->childAssets($attributes['id']);

        // MUST FIRST BE INITIATED TO NULL:
        $attributes['parent_id'] = null;

        foreach ($children as $child) {
            File::create($this->fileProperties($child, $attributes)->toArray());

            // ORDER MATTERS HERE MUST BE AFTER FILE CREATION:
            $attributes['parent_id'] = File::where('remote_service_file_id', $children[0]['id'])->first()?->id ?? null;
        }

        // WE ARE DELETING BECAUSE WE ARE GOING TO RECREATE THE VERSION STACK
        // AND FIRST ITEM IS NOT A VIDEO FILE ANYWAY.
        $file->fresh();
        $file->forceDelete();

        return true;
    }

    public function getThumbnailPath($file): string
    {
        $frameio = $this->initialize($this->getAccessToken());
        $thumbnail = $frameio->downloadThumb(data_get($file, 'thumbnail'));

        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            $file['id'],
            str()->slug($file['id']) . '.jpg'
        );

        if (empty($thumbnail)) {
            $thumbnail = '';
        }

        $this->storage->put($thumbnailPath, $thumbnail);

        return $thumbnailPath;
    }

    /**
     * @throws \Throwable
     */
    public function getAccessToken(bool $forceRefresh = false): ?string
    {
        throw_unless($this->service, CouldNotInitializePackage::class, 'Service is not initialized');

        if ($forceRefresh || $this->checksForAccessTokenExpiry($this->service->expires)) {
            $authHelper = $this->initialize()->getAuthHelper();
            $token = $authHelper->getRefreshedAccessToken($this->service->refresh_token, $this->scope);

            if (! $token || ! $token->getToken()) {
                $this->httpStatus = 400;
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);

                return null; // Ensure to return null if token refresh fails
            }

            $this->service->access_token = $token->getToken();
            $this->service->refresh_token = $token->getRefreshToken();
            $this->service->expires = now()->addSeconds($token->getExpiryTime())->getTimestamp();
            $this->service->save();
        }

        return $this->service->access_token;
    }

    public function checksForAccessTokenExpiry($expires): bool
    {
        // subtract some time from the real expiry time to force a token refresh
        // ahead of when it's actually needed; then check if the modified expiry time
        // has passed.
        return Carbon::createFromTimestamp($expires)->subMinutes(2)->isPast();
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        $this->getAccessToken();

        if (! $downloadUrl = $this->getDownloadUrl($file)) {
            $this->log('Could not get download url', 'error');

            return false;
        }

        // Download in 5 MB chunks:
        $chunkSizeBytes = config('manager.chunk_size', 5) * 1048576;
        $chunkStart = 0;
        $response = false;

        try {
            while (true) {
                $chunkEnd = $chunkStart + $chunkSizeBytes;

                $response = Http::timeout(config('queue.timeout'))
                    ->withHeaders(['Range' => "bytes={$chunkStart}-{$chunkEnd}"])
                    ->get($downloadUrl);

                if ($response->status() === Response::HTTP_PARTIAL_CONTENT) {
                    $chunkStart = $chunkEnd + 1;
                    $this->downstreamToTmpFile($response->body());
                } else {
                    break;
                }
            }
        } catch (Exception $e) {
            // Handle partial content
            if ($response && $response->status() !== Response::HTTP_PARTIAL_CONTENT) {
                $this->log("Error getting download link: {$e->getMessage()}", 'error');

                $file->markFailure(
                    FileOperationName::DOWNLOAD,
                    'Error downloading file',
                    $e->getMessage()
                );

                return false;
            }

            $this->downstreamToTmpFile($response->body());
            $this->log('File download completed');
        }

        $path = $this->downstreamToTmpFile(null, $this->prepareFileName($file));

        $file->update(['size' => $this->getFileSize($path)]);

        return $path;
    }

    public function getDownloadUrl(File $file): string|bool
    {
        $attempts = 0;
        $maxAttempts = 2;

        while ($attempts < $maxAttempts) {
            try {
                $response = Http::maxRedirects(10)
                    ->withToken($this->getAccessToken() ?? '')
                    ->get(config('frameio.baseUrl') . "/assets/{$file->remote_service_file_id}", [
                        'include_deleted' => 'true',
                        'type'            => 'file',
                    ]);

                if ($response->unauthorized()) {
                    // sleep for 750ms to avoid rate limiting
                    Sleep::for(750)->milliseconds();

                    $this->getAccessToken(true); // force token refresh
                    $attempts++;

                    continue; // retry
                }

                return data_get($response->collect(), 'original', false);
            } catch (Exception $e) {
                $this->log("Error getting download link: {$e->getMessage()}", 'error');

                return false;
            }
        }

        return false;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        $key = $this->prepareFileName($file);
        $uploadId = $this->createMultipartUpload($key, $file->mime_type);

        throw_unless($uploadId, CouldNotDownloadFile::class, 'Failed to initialize multi-part upload');

        $chunkSize = config('manager.chunk_size', 5) * 1024 * 1024;
        $partNumber = 1;
        $parts = [];

        try {
            $frameio = $this->initialize($this->getAccessToken());
            $endpoint = "assets/{$file->remote_service_file_id}";
            $body = ['query' => ['include_deleted' => true, 'type' => 'file']];
            $content = json_decode($frameio->callAPI('GET', $endpoint, $body)->getBody()->getContents(), true);
            $downloadUrl = $content['original'];
            $response = $frameio->callAPI('GET', $downloadUrl);

            while (! $response->getBody()->eof()) {
                $parts[] = $this->uploadPart($key, $uploadId, $partNumber++, $response->getBody()->read($chunkSize));
            }

            return $this->completeMultipartUpload($key, $uploadId, $parts);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return false;
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        $code = request('code');
        $scope = request('scope');
        $state = request('state');
        $error = request('error');
        $grantType = 'authorization_code';
        $this->callbackUrl = $this->getSettings()['redirectUri'];

        throw_unless($code || $error, CouldNotGetToken::class, 'Either authorization code or error must be provided');
        throw_unless($this->callbackUrl, CouldNotGetToken::class, 'Callback URL cannot be empty');
        throw_unless(empty($error), CouldNotGetToken::class, (string) $error);

        $authHelper = $this->initialize()->getAuthHelper();
        $token = $authHelper->getAccessToken($code, $scope, $grantType, $state, $this->callbackUrl);

        throw_unless((bool) $token?->access_token, CouldNotGetToken::class, 'Failed to get access token');

        $this->accessToken = $token->access_token;

        return new TokenDTO([
            'access_token'  => $token->access_token,
            'expires'       => now()->addseconds($token->getExpiryTime())->getTimestamp(),
            'refresh_token' => $token->getRefreshToken(),
            'scope'         => $token->getScope(),
            'token_type'    => $token->getTokenType(),
        ]);
    }

    public function downloadFromService(File $file): bool|StreamedResponse|BinaryFileResponse
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        try {
            $frameio = $this->initialize($this->getAccessToken());
            $endpoint = "assets/{$file->remote_service_file_id}";
            $body = ['query' => ['include_deleted' => true, 'type' => 'file']];
            $content = json_decode($frameio->callAPI('GET', $endpoint, $body)->getBody()->getContents(), true);
            $downloadUrl = $content['original'];
            $response = $frameio->callAPI('GET', $downloadUrl);

            // Create a streamed response
            $response = response()->streamDownload(function () use ($response) {
                echo $response->getBody()->getContents();
            }, $file->name);

            // Set headers for file download
            $response->headers->set('Content-Type', $file->mime_type);
            $response->headers->set('Content-Disposition', "attachment; filename={$file->name}.{$file->extension}");
            $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
            $response->headers->set('Pragma', 'public');

            return $response;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return false;
    }

    public function commentProperties($comment, $attr): CommentDTO
    {
        // SEE: packages/clickonmedia/frameio/Responses/Comments.json
        return new CommentDTO([
            'user_id'    => $attr['user_id'],
            'type'       => self::getServiceName(),
            'identifier' => $comment['id'],
            'content'    => $comment['text'],
            // NOTE: convert to seconds not milliseconds:
            'timestamp'  => isset($attr['fps']) ? $comment['frame'] / $attr['fps'] : 0,
            'sketch'     => [],
            'created_at' => $comment['inserted_at'],
            'updated_at' => $comment['updated_at'],
        ]);
    }

    public function getComments(string|int $fileId): array
    {
        $frameio = $this->initialize($this->getAccessToken());

        try {
            return $frameio->comments($fileId);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return [];
    }

    public function comment(string|int $commentId): array
    {
        $frameio = $this->initialize($this->getAccessToken());

        try {
            return $frameio->comment($commentId);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return [];
    }

    public function listFolderSubFolders(?array $request): iterable
    {
        if (isset($request['folder_ids'])) {
            foreach ($request['folder_ids'] as $folderId) {
                yield from $this->getFilesInFolder($folderId);
            }
        }

        if (isset($request['file_ids'])) {
            foreach ($request['file_ids'] as $fileId) {
                try {
                    $frameio = $this->initialize($this->getAccessToken());
                    $fileResponse = $frameio->callAPI('GET', "assets/{$fileId}");
                    $file = json_decode($fileResponse->getBody()->getContents(), true);
                    yield $file;
                } catch (Exception $e) {
                    $this->log($e->getMessage(), 'error');
                }
            }
        }
    }

    public function getFilesInFolder(?string $folderId = 'root'): iterable
    {
        try {
            // listFolderContent now yields each item.
            foreach ($this->listFolderContent(['folder_id' => $folderId]) as $item) {
                if (isset($item['type']) && $item['type'] === 'folder') {
                    yield from $this->getFilesInFolder($item['id']);
                } else {
                    yield $item;
                }
            }
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = $request['folder_id'] ?? null;

        try {
            $frameio = $this->initialize($this->getAccessToken());

            $items = $folderId === 'root' || ! $folderId
                ? $this->index([], true)
                : $frameio->childAssets($folderId);

            $newItems = [];

            foreach ($items as $item) {
                $newItems[] = [
                    'isDir' => $item['type'] === 'folder',
                    'name'  => $item['name'],
                    ...$item,
                ];
            }

            return $newItems;
        } catch (Throwable|Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return [];
    }

    public function index(?array $params = [], $folders = false): iterable
    {
        $options = $this->service->options;

        throw_unless(isset($options['accountId']),
            CouldNotInitializePackage::class,
            'Account ID is not set in options');

        $accountId = $options['accountId'];
        $frameio = $this->initialize($this->getAccessToken());

        try {
            return $frameio->search($accountId, $folders);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return [];
        }
    }

    public function serviceProperties(array $service): ServiceDTO
    {
        if (array_key_exists('accountId', $service)) {
            $service['options'] = ['accountId' => data_get($service, 'accountId')];
        }

        return parent::serviceProperties($service);
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Frame.io settings are required');
        abort_if(count(config('frameio.settings')) !== $settings->count(), 406, 'All Settings must be present');

        // Pattern for Frame.io client_id (UUID format)
        $clientIdPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        // Pattern for Frame.io client_secret (no specific length constraint here)
        $clientSecretPattern = '/^[a-zA-Z0-9~.-]+$/';

        $clientId = $settings->firstWhere('name', 'FRAMEIO_CLIENT_ID')?->payload ?? '';
        $clientSecret = $settings->firstWhere('name', 'FRAMEIO_CLIENT_SECRET')?->payload ?? '';

        abort_unless(preg_match($clientIdPattern, $clientId), 406, 'Looks like your client ID format is invalid');
        abort_unless(preg_match($clientSecretPattern, $clientSecret),
            406,
            'Looks like your client secret format is invalid');

        try {
            $frameio = $this->initialize();
            $accountInfo = $frameio->getCurrentAccount();

            abort_unless((bool) $accountInfo, 406, 'API call to fetch account info failed');

            return true;
        } catch (Exception $e) {
            abort(406, 'Error during API call: ' . $e->getMessage());
        }
    }
}
