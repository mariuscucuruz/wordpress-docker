<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Vimeo;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Utils;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Vimeo\Vimeo as VimeoSdk;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Database\Eloquent\Model;
use MariusCucuruz\DAMImporter\DTOs\CommentDTO;
use MariusCucuruz\DAMImporter\DTOs\ServiceDTO;
use GuzzleHttp\Exception\GuzzleException;
use Vimeo\Exceptions\VimeoRequestException;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasComments;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Interfaces\HasRenditions;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;

class Vimeo extends SourceIntegration implements CanPaginate, HasComments, HasFolders, HasMetadata, HasRenditions, HasSettings, IsTestable
{
    public ?string $accessToken;

    private Client $client;

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $vimeo = $this->initialize();
        $this->settings = $settings;

        $state = json_encode(['settings' => $this->settings?->pluck('id')?->toArray()], JSON_THROW_ON_ERROR);

        throw_unless(
            data_get($this->getSettings(), 'redirectUri'),
            CouldNotInitializePackage::class,
            'Vimeo settings are required!'
        );

        $this->redirectTo($vimeo->buildAuthorizationEndpoint(
            $this->getSettings()['redirectUri'],
            config('vimeo.scope'),
            $state)
        );
    }

    public function initialize(?string $accessToken = null)
    {
        $this->client = new Client;

        $settings = $this->getSettings();

        if (! isset($settings['clientId'], $settings['clientSecret'])) {
            $this->log('Vimeo Client ID and Client Secret are required', 'error');

            return;
        }

        return new VimeoSdk(
            $settings['clientId'],
            $settings['clientSecret'],
            $accessToken ?? $this->accessToken ?? $this->service?->access_token ?? ''
        );
    }

    public function getSettings($customKeys = null): array
    {
        $settings = parent::getSettings($customKeys);

        $clientId = $settings['VIMEO_CLIENT_ID'] ?? config('vimeo.client_id');
        $clientSecret = $settings['VIMEO_CLIENT_SECRET'] ?? config('vimeo.client_secret');

        $redirectUri = config('vimeo.redirect_uri');

        return compact('clientId', 'clientSecret', 'redirectUri');
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        $code = request('code');

        if (empty($code)) {
            $this->redirectTo('/catalogue');
        }

        try {
            return retry(3, function () use ($code) {
                $state = request('state');
                $vimeo = $this->initialize();
                $redirectUri = $this->getSettings()['redirectUri'];

                $token = $vimeo->accessToken($code, $redirectUri);

                if (! $token) {
                    throw new RuntimeException('Token is null, retrying...');
                }

                $this->accessToken = $token['body']['access_token'];

                return new TokenDTO([
                    'access_token' => $token['body']['access_token'],
                    'token_type'   => $token['body']['token_type'],
                    'scope'        => $token['body']['scope'],
                ]);
            }, function ($attempt) {
                return 1000 * (2 ** ($attempt - 1)); // exponential delay: 1s, 2s, 4s
            });
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    public function getUser(): ?UserDTO
    {
        try {
            $vimeo = $this->initialize($this->accessToken);
            $account = $vimeo->request('/me');

            return new UserDTO([
                'email'        => $account['body']['email'] ?? $account['body']['name'], // API does not provide email
                'photo'        => $account['body']['pictures']['sizes'][0]['link'] ?? null,
                'name'         => $account['body']['name'],
                'account_type' => $account['body']['account'],
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return new UserDTO;
        }
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $thumbnailPath = data_get($file, 'pictures.sizes.0.link');

        $name = pathinfo(data_get($file, 'name'), PATHINFO_FILENAME);
        $mimeType = data_get($file, 'files.0.type');
        $extension = $this->getMimeTypeOrExtension($mimeType);
        $type = dirname($mimeType ?? '') ?: $this->getFileTypeFromExtension($extension);

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => $this->uniqueFileId($file),
            'name'                   => $name,
            'thumbnail'              => $thumbnailPath,
            'mime_type'              => $mimeType,
            'type'                   => $type,
            'extension'              => $extension,
            'resolution'             => isset($file['width'], $file['height']) ? "{$file['width']}x{$file['height']}" : null,
            'duration'               => data_get($file, 'duration') * 1000 ?? null,
            'slug'                   => str()->slug(pathinfo($name, PATHINFO_FILENAME)),
            'created_time'           => isset($file['created_time'])
                ? Carbon::parse($file['created_time'])->format('Y-m-d H:i:s')
                : null,
            'modified_time' => isset($file['modified_time'])
                ? Carbon::parse($file['modified_time'])->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public function uploadThumbnail($file): string
    {
        $remoteThumbnailUrl = data_get($file, 'pictures.sizes.0.link');

        if (! $remoteThumbnailUrl) {
            return '';
        }
        $thumbnail = $this->getThumbnail($remoteThumbnailUrl);

        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            $file['id'],
            str()->slug(pathinfo($file['uri'], PATHINFO_FILENAME)) . '.jpg'
        );

        $this->storage->put($thumbnailPath, $thumbnail ?? '');

        return $thumbnailPath;
    }

    public function getThumbnail($fileUrl)
    {
        try {
            $response = $this->client->request('GET', $fileUrl);
            $this->httpStatus = $response->getStatusCode();

            if ($response->getStatusCode() == 200) {
                return $response->getBody()->getContents();
            }
        } catch (GuzzleException|Exception $e) {
            $this->httpStatus = $e->getCode();
            $this->log($e->getMessage(), 'error');
        }

        return null;
    }

    public function uniqueFileId($attribute, $key = 'uri'): mixed
    {
        return pathinfo($attribute[$key], PATHINFO_FILENAME);
    }

    public function downloadTemporary(File $file, ?string $rendition = 'source'): string|bool
    {
        $this->validateFileAndOptions($file);

        $path = Path::join(
            config('manager.directory.originals'),
            $file->id,
            str($file->slug . '-' . str()->random(10) . '.' . $file->extension)->lower()->toString()
        );

        $tempFilePath = tempnam(sys_get_temp_dir(), config('vimeo.name') . '_');

        throw_unless($tempFilePath, CouldNotDownloadFile::class, 'Temporary file not found!');

        $accessToken = $this->service->access_token;
        $vimeo = $this->initialize($accessToken);
        $response = $vimeo->request("/videos/{$file->remote_service_file_id}");
        $this->httpStatus = data_get($response, 'status');

        if (in_array($this->httpStatus, [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED])) {
            $this->cleanupTemporaryFile($tempFilePath);

            return false;
        }

        try {
            $resource = Utils::tryFopen($tempFilePath, 'w');
            $stream = Utils::streamFor($resource);

            $downloads = data_get($response, 'body.download', []);
            $renditions = [$rendition, '1080p', '720p']; // order matters!
            $original = null;

            foreach ($renditions as $rend) {
                foreach ($downloads as $download) {
                    if (data_get($download, 'rendition') == $rend) {
                        $original = data_get($download, 'link');

                        break 2; // breaks both foreach loops
                    }
                }
            }

            // If no matching rendition is found, default to the first download link
            if (! $original && count($downloads) > 0) {
                $original = $downloads[0]['link'];
            }

            throw_unless($original, CouldNotDownloadFile::class, 'Vimeo original, 1080p or 720p file not found');

            $this->client->request('GET', $original, ['sink' => $stream]);
            $this->storage->put($path, fopen($tempFilePath, 'r'));

            $size = $this->getFileSize($path);

            if ($size) {
                $file->update(compact('size'));
            }

            $this->cleanupTemporaryFile($tempFilePath);

            return $path;
        } catch (VimeoRequestException|GuzzleException|Throwable|Exception $e) {
            $this->httpStatus = $e->getCode();

            $this->log($e->getMessage(), 'error');
        } finally {
            $this->cleanupTemporaryFile($tempFilePath);
        }

        return false;
    }

    public function downloadMultiPart(File $file, ?string $rendition = 'source'): string|bool
    {
        $this->validateFileAndOptions($file);

        $accessToken = $this->service->access_token;
        $vimeo = $this->initialize($accessToken);
        $response = $vimeo->request("/videos/{$file->remote_service_file_id}");
        $this->httpStatus = data_get($response, 'status');

        if (in_array($this->httpStatus, [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED])) {
            return false;
        }

        $downloads = data_get($response, 'body.download');
        $renditions = [$rendition, '1080p', '720p']; // order matters!
        $downloadUrl = null;

        foreach ($renditions as $rend) {
            foreach ($downloads as $download) {
                if (data_get($download, 'rendition') == $rend) {
                    $downloadUrl = data_get($download, 'link');

                    break 2; // breaks both foreach loops
                }
            }
        }

        // If no matching rendition is found, default to the first download link
        if (! $downloadUrl && count($downloads) > 0) {
            $downloadUrl = $downloads[0]['link'];
        }

        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'Vimeo original, 1080p or 720p file not found');

        return $this->handleMultipartDownload($file, $downloadUrl);
    }

    public function downloadRendition(Model|File $file): string|bool
    {
        return $this->downloadFile($file, '720p');
    }

    public function downloadFromService(File $file): StreamedResponse|bool
    {
        $this->validateFileAndOptions($file);

        $accessToken = $this->service->access_token;
        $vimeo = $this->initialize($accessToken);
        $response = $vimeo->request("/videos/{$file->remote_service_file_id}");

        $downloads = $response['body']['download'];
        $original = null;

        foreach ($downloads as $download) {
            if ($download['quality'] == 'source') {
                $original = $download['link'];

                break;
            }
        }

        throw_unless($original, CouldNotDownloadFile::class, 'Vimeo original, 1080p or 720p file not found');

        $chunkSizeBytes = config('manager.chunk_size', 5) * 1024 * 1024;
        $chunkStart = 0;

        $response = response()->streamDownload(function () use (&$chunkStart, $chunkSizeBytes, $original) {
            while (true) {
                $chunkEnd = $chunkStart + $chunkSizeBytes;

                try {
                    $response = $this->client->request('GET', $original, [
                        'headers' => [
                            'Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                        ],
                    ]);
                } catch (Exception $e) {
                    if ($e->getCode() == Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
                        break;
                    }
                }

                if ($response && $response?->getStatusCode() !== Response::HTTP_PARTIAL_CONTENT) { // @phpstan-ignore-line
                    break;
                }

                $chunkStart = $chunkEnd + 1;
                echo $response->getBody()->getContents();
            }
        }, $file->name);

        $response->headers->set('Content-Type', $file->mime_type);
        $response->headers->set('Content-Disposition',
            'attachment; filename="' . $file->name . '.' . $file->extension . '"');
        $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $response->headers->set('Pragma', 'public');

        return $response;
    }

    public function commentProperties(mixed $comment, array $attr): CommentDTO
    {
        // SEE: packages/clickonmedia/vimeo/Responses/Comments.json
        return new CommentDTO([
            'user_id'    => $attr['user_id'],
            'type'       => self::getServiceName(),
            'identifier' => pathinfo($comment['uri'], PATHINFO_FILENAME),
            'content'    => $comment['text'],
            // NOTE: convert to seconds not milliseconds:
            // 'timestamp'  => isset($attr['fps']) ? $comment['frame'] / $attr['fps'] : 0,
            'sketch'     => [],
            'created_at' => $comment['created_on'],
            'updated_at' => $comment['updated_at'] ?? null,
        ]);
    }

    public function getComments(string|int $fileId): array
    {
        $vimeo = $this->initialize($this->service->access_token);

        try {
            $comments = $vimeo->request("/videos/{$fileId}/comments");

            if ($comments['status'] == Response::HTTP_OK) {
                return $comments['body']['data'];
            }
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return [];
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = $request['folder_id'] ?? 'root';

        try {
            if (! $folderId || $folderId === 'root') {
                $files = $this->getProjects();
            } else {
                $files = $this->getFilesInFolder($folderId);
            }
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            $files = [];
        }

        // Combine videos that don't belong to any albums
        return [...$files, ...$this->getFilesInFolder()];
    }

    public function getFilesInFolder(?string $folderId = 'root'): iterable
    {
        $vimeo = $this->initialize($this->service->access_token);
        $response = $vimeo->request("/me/projects/{$folderId}/videos");

        return $this->processResponseData($response, false);
    }

    public function listFolderSubFolders(?array $request): iterable
    {
        $filesFromFolders = [];
        $filesFromIds = [];

        if (isset($request['folder_ids'])) {
            $filesFromFolders = array_reduce(
                $request['folder_ids'],
                fn ($carry, $folderId) => [...$carry, ...$this->getFilesInFolder($folderId)],
                []
            );
        }

        if (isset($request['file_ids'])) {
            $filesFromIds = array_reduce(
                $request['file_ids'],
                fn ($carry, $fileId) => [...$carry, $this->getVideo($fileId)],
                []
            );
        }

        return [...$filesFromFolders, ...$filesFromIds];
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Vimeo settings are required');
        abort_if(count(config('vimeo.settings')) !== $settings->count(), 406, 'All Settings must be present');

        $clientIdPattern = '/^[a-z0-9]{40,}$/'; // 40 characters
        $clientSecretPattern = '/^[A-Za-z0-9_\/+]{86,}$/'; // 86 characters

        $clientId = $settings->firstWhere('name', 'VIMEO_CLIENT_ID')?->payload ?? '';
        $clientSecret = $settings->firstWhere('name', 'VIMEO_CLIENT_SECRET')?->payload ?? '';

        abort_unless(preg_match($clientIdPattern, $clientId), 406, 'The client ID format is invalid.');
        abort_unless(preg_match($clientSecretPattern, $clientSecret), 406, 'The client secret format is invalid.');

        try {
            abort_unless($this->initialize(), 406, 'Invalid credentials');
        } catch (Exception $e) {
            abort(500, $e->getMessage());
        }

        return true;
    }

    public function handleTokenExpiration()
    {
        // Assuming you have a field in your database to track the last used time of the token
        $lastUsed = $this->service->updated_at ?? $this->service->created_at;
        $currentTime = now();

        // Check if the token has been inactive for 30 days
        if ($lastUsed->diffInDays($currentTime) > 30) {
            $this->refreshToken();
        } else {
            $this->service->updated_at = $currentTime;
            $this->service->save();
        }
    }

    public function serviceProperties(array $service): ServiceDTO
    {
        $service['options'] = ['account_type' => data_get($service, 'account_type')];

        return parent::serviceProperties($service);
    }

    private function validateFileAndOptions(Model $file): void
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');
        $options = $this->service->options;
        throw_unless($options, CouldNotDownloadFile::class, 'Vimeo options is missing');
        throw_unless(isset($options['account_type']), CouldNotDownloadFile::class, 'Vimeo options account_type is missing');
        throw_if($options['account_type'] === 'free', CouldNotDownloadFile::class, 'Please Upgrade your account');
    }

    private function getProjects(): array
    {
        $vimeo = $this->initialize($this->service->access_token);
        $response = $vimeo->request('/me/projects');

        return $this->processResponseData($response, true);
    }

    private function processResponseData(array $response, bool $isDir): array
    {
        $files = [];
        $this->httpStatus = data_get($response, 'status');

        if ($this->httpStatus === Response::HTTP_OK) {
            foreach ($response['body']['data'] as $data) {
                $files[] = [
                    'id'           => basename($data['uri']),
                    'isDir'        => $isDir,
                    'thumbnailUrl' => '',
                    ...$data,
                ];
            }
        }

        return $files;
    }

    private function getVideo(string $fileId): array
    {
        $vimeo = $this->initialize($this->service->access_token);
        $response = $vimeo->request("/videos/{$fileId}");
        $this->httpStatus = data_get($response, 'status');

        if ($this->httpStatus === Response::HTTP_OK) {
            return $response['body'];
        }

        return [];
    }

    private function refreshToken()
    {
        try {
            $settings = $this->getSettings();
            $clientId = $settings['clientId'];
            $clientSecret = $settings['clientSecret'];

            $response = $this->client->post(config('vimeo.authorizeUrl') . '/client', [
                'auth'        => [$clientId, $clientSecret],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                ],
            ]);

            if ($response->getStatusCode() !== Response::HTTP_OK) {
                throw new CouldNotGetToken("Vimeo token request failed with status code {$response->getStatusCode()}");
            }

            $body = json_decode((string) $response->getBody(), true);

            if (isset($body['access_token'])) {
                $this->service->access_token = $body['access_token'];
                $this->service->updated_at = now();
                $this->service->save();

                $this->accessToken = $body['access_token'];
            }
        } catch (GuzzleException|Exception $e) {
            if ($e->getCode() === 401) {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }
            $this->log($e->getMessage(), 'error');

            throw $e;
        }
    }

    public function paginate(?array $request = []): void
    {
        $this->initialize(); // refreshes the token if expired

        if (! isset($request['folder_ids'])) {
            $this->getAllFiles();

            return;
        }

        foreach ($request['folder_ids'] as $folderId) {
            $this->getProjectFiles($folderId);
        }
    }

    public function getProjectFiles(?string $folderId = 'root', $folderName = null, $nextPage = null): void
    {
        $folderName = $folderName ?? $folderId;

        if (empty($nextPage)) {
            $nextPage = 1;
        }

        $perPage = config('vimeo.per_page');

        try {
            $response = $this->getResponse("/me/projects/{$folderId}/videos", [
                'page'     => $nextPage,
                'per_page' => $perPage,
            ]);

            $this->httpStatus = data_get($response, 'status');
            $files = data_get($response, 'body.data', []);

            if (empty($files) || $this->httpStatus > 200) {
                return;
            }

            $filesOnly = [];

            foreach (array_filter($files) as $item) {
                if (! is_array($item) || empty($item)) {
                    continue;
                }

                if (data_get($item, 'type') === 'folder') {
                    $this->getProjectFiles($item['id'], $item['name'], $nextPage);
                } else {
                    $filesOnly[] = $item;
                }
            }

            $this->dispatch($filesOnly, $folderName);

            if (! empty($response['body']['paging']['next']) && $nextPage <= 3) {
                $this->getProjectFiles($folderId, $folderName, ++$nextPage);
            }
        } catch (Exception $e) {
            logger()->error($e->getMessage());
            $this->httpStatus = $e->getCode();
        }
    }

    public function getResponse($endpoint, array $params)
    {
        $accessToken = $this->service->access_token;

        throw_unless($accessToken, CouldNotGetToken::class, 'Access token is not set');

        return $this->initialize($accessToken)->request($endpoint, $params);
    }

    public function getAllFiles(?array $params = [], ?int $nextPage = 1): void
    {
        try {
            $response = $this->getResponse('/me/videos', [
                'page'     => $nextPage,
                'per_page' => config('vimeo.per_page'),
                ...$params,
            ]);

            $this->httpStatus = data_get($response, 'status');
            $responseBody = data_get($response, 'body.data');

            if (empty($responseBody) || $this->httpStatus > 200) {
                return;
            }

            $this->dispatch($responseBody, 'root');

            if (data_get($response, 'body.paging.next')) {
                $this->getAllFiles($params, ++$nextPage);
            }
        } catch (Exception $e) {
            $this->httpStatus = $e->getCode();
            logger()->error($e->getMessage());
        }
    }
}
