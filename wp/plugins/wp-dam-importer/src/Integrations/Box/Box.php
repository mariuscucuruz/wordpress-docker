<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Box;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use GuzzleHttp\Client;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\DTOs\IntegrationDefinition;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Routing\UrlGenerator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\PackageTypes\IsSource
use MariusCucuruz\DAMImporter\Exceptions\CouldNotQuery;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;
use MariusCucuruz\DAMImporter\SourcePackageManager;

class Box extends SourcePackageManager implements HasFolders, HasMetadata
{
    // Docs: https://developer.box.com/reference/
    public Client $client;

    private ?string $clientId;

    private ?string $clientSecret;

    private ?string $redirectUri;

    private ?string $accessToken;

    private string $apiUrl;

    public static function definition(): IntegrationDefinition
    {
        return IntegrationDefinition::make('box', 'Box', BoxServiceProvider::class,
            ['MariusCucuruz\DAMImporter\Integrations\\Box' => __NAMESPACE__],
        );
    }

    public function initialize()
    {
        $this->client = new Client;
        $settings = $this->getSettings();
        $this->clientId = $settings['clientId'];
        $this->clientSecret = $settings['clientSecret'];
        $this->redirectUri = $settings['redirectUri'] ? url($settings['redirectUri'], app()->isProduction()) : null;

        $this->apiUrl = config('box.query_base_url') . '/2.0';

        $this->handleTokenExpiration();
    }

    public function getSettings($customKeys = null): array
    {
        $settings = parent::getSettings($customKeys);

        $clientId = $settings['BOX_CLIENT_ID'] ?? config('box.client_id');
        $clientSecret = $settings['BOX_CLIENT_SECRET'] ?? config('box.client_secret');
        $redirectUri = url(config('box.redirect_uri'), secure: app()->isProduction());

        if ($redirectUri instanceof UrlGenerator) {
            $redirectUri = $redirectUri->to(path: config('box.redirect_uri'), secure: app()->isProduction());
        }

        return compact('clientId', 'clientSecret', 'redirectUri');
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        try {
            $url = config('box.oauth_base_url') . '/oauth2/authorize';

            throw_unless(
                $url && $this->clientId && $this->redirectUri,
                CouldNotInitializePackage::class,
                'Box settings are required!'
            );

            $queryParams = [
                'client_id'     => $this->clientId,
                'redirect_uri'  => $this->redirectUri,
                'response_type' => 'code',
                'state'         => json_encode(['settings' => $this->settings?->pluck('id')?->toArray()]),
            ];

            $queryString = http_build_query($queryParams);
            $requestUrl = "{$url}?{$queryString}";
            $this->redirectTo($requestUrl);
        } catch (CouldNotInitializePackage|CouldNotQuery|Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        try {
            $response = $this->client->post(config('box.oauth_base_url') . '/oauth2/token', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code'          => request('code'),
                    'grant_type'    => 'authorization_code',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            throw_unless($body, CouldNotGetToken::class, 'Invalid token response.');

            return new TokenDTO($this->storeToken($body));
        } catch (CouldNotGetToken|Exception $e) {
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    public function storeToken($body): array
    {
        throw_if(
            ! isset($body['access_token']) || ! isset($body['token_type']),
            CouldNotGetToken::class,
            'Invalid token response.'
        );

        $this->accessToken = $body['access_token'];
        $expires = isset($body['expires_in']) ? now()->addSeconds($body['expires_in'])->getTimestamp() : null;

        return [
            'access_token'  => $this->accessToken,
            'token_type'    => data_get($body, 'token_type'),
            'expires'       => $expires,
            'token'         => null,
            'refresh_token' => data_get($body, 'refresh_token'),
        ];
    }

    public function isAccessTokenExpired(): bool
    {
        return $this->service &&
                (! $this->service->access_token
                || ! $this->service->expires
                || now()->gt(Carbon::createFromTimestamp($this->service->expires)));
    }

    public function getUser(): ?UserDTO
    {
        try {
            $response = $this->client->get("{$this->apiUrl}/users/me", [
                'query' => [
                    'access_token' => $this->accessToken,
                    'Accept'       => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            throw_if(! isset($body['name'], $body['login']),
                CouldNotQuery::class,
                'Neither name nor email found in the response');

            return new UserDTO([
                'email'   => $body['login'] ?? $body['name'],
                'photo'   => null,
                'name'    => $body['name'] ?? null,
                'user_id' => $body['id'] ?? null,
            ]);
        } catch (Exception|Throwable $e) {
            $this->log($e->getMessage(), 'error');
        }

        return null;
    }

    public function index(?array $params = []): iterable
    {
        return $this->processFolders([['id' => 0]]); // root id
    }

    public function processFolders($foldersToProcess = []): array
    {
        $files = [];

        while ($foldersToProcess) {
            $folder = array_pop($foldersToProcess);
            $items = $this->getItemsInFolder($folderId = data_get($folder, 'id'));

            if (data_get($items, 'folders')) {
                $foldersToProcess = [...$foldersToProcess, ...$items['folders']];
            }

            if ($items = data_get($items, 'files')) {
                $newFiles = collect($items)->map(function ($item) use ($folderId) {
                    $item['folder_id'] = $folderId;

                    return $item;
                })->toArray();

                $files = [...$files, ...$newFiles];
            }
        }

        return $files;
    }

    public function getItemsInFolder($folderId, $folderType = 'folders'): array
    {
        if ($this->isAccessTokenExpired()) {
            $this->refreshAccessToken();
        }

        if (empty($folderId)) {
            return [];
        }

        $results = [
            'folders' => [],
            'files'   => [],
        ];

        try {
            $response =
                $this->client->get("{$this->apiUrl}/{$folderType}/{$folderId}/items", [
                    'query' => [
                        'access_token' => $this->service->access_token,
                        'fields'       => 'id,type,name,extension,item_status,metadata,size,created_at,modified_at,shared_link',
                    ],
                ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $items = data_get($body, 'entries');

            foreach ($items as $item) {
                if (data_get($item, 'type') == 'folder') {
                    $results['folders'][] = $item;
                } elseif (data_get($item, 'type') == 'file') {
                    $fileExtension = data_get($item, 'extension');

                    if (in_array($fileExtension, config('manager.meta.file_extensions'))) {
                        $item['folder_id'] = $folderId;
                        $results['files'][] = $item;
                    }
                }
            }
        } catch (Exception|GuzzleException $e) {
            $this->log($e->getMessage(), 'error');

            return [];
        }

        return $results;
    }

    public function getThumbnailUrl($file): ?string
    {
        try {
            $response = $this->client->get("{$this->apiUrl}/files/" . data_get($file, 'remote_service_file_id') . '/thumbnail.jpg', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->service->access_token,
                    'Accept'        => 'application/json',
                ],
                'query' => [
                    'min_height' => 320,
                    'min_width'  => 320,
                ],
            ]);

            if ($response->getStatusCode() == '200') {
                return $response->getBody()->getContents();
            }
        } catch (Exception $e) {
            $this->log($e->getMessage());
        }

        return null;
    }

    public function getThumbnailPath(mixed $file): ?string
    {
        $binaryData = $this->getThumbnailUrl($file);

        if (! $binaryData) {
            return null;
        }

        $thumbnailPath = Path::join(config('manager.directory.thumbnails'), str()->random(6), str()->random(6) . '.jpg');

        $this->storage->put($thumbnailPath, $binaryData);

        return $thumbnailPath;
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $thumbnailPath = "{$this->apiUrl}/files/" . data_get($file, 'id') . '/thumbnail.jpg';

        if ($createThumbnail) {
            $thumbnailPath = $this->getThumbnailPath($file);
        }

        $fileType = $this->getFileTypeFromExtension(data_get($file, 'extension'));
        $this->getMimeTypeOrExtension(data_get($file, 'extension'));

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => data_get($file, 'id'),
            'size'                   => data_get($file, 'size'),
            'name'                   => pathinfo($file['name'], PATHINFO_FILENAME),
            'thumbnail'              => $thumbnailPath,
            'mime_type'              => Path::join($fileType, data_get($file, 'extension')),
            'type'                   => $fileType,
            'extension'              => data_get($file, 'extension'),
            'slug'                   => str()->slug(pathinfo(data_get($file, 'name'), PATHINFO_FILENAME)),
            'created_time'           => $file['created_at']
                ? Carbon::parse($file['created_at'])->format('Y-m-d H:i:s')
                : null,
            'modified_time' => isset($file['modified_at'])
                ? Carbon::parse($file['modified_at'])->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    /**
     * @throws Throwable
     */
    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $chunkSizeBytes = config('manager.chunk_size', 5) * 1048576;
        $chunkStart = 0;

        try {
            while (true) {
                $chunkEnd = $chunkStart + $chunkSizeBytes;
                $response = $this->client->request('GET', "{$this->apiUrl}/files/{$file->remote_service_file_id}/content", [
                    'headers' => [
                        'Range'         => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                        'Authorization' => 'Bearer ' . $this->service->access_token,
                    ],
                ]);

                $this->httpStatus = $response->getStatusCode();

                if (in_array($this->httpStatus, [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED])) {
                    $file->markFailure(
                        FileOperationName::DOWNLOAD,
                        'Failed to download file from Box.',
                    );

                    return false;
                }

                if ($response->getStatusCode() !== Response::HTTP_PARTIAL_CONTENT) {
                    break;
                }

                $chunkStart = $chunkEnd + 1;

                $this->downstreamToTmpFile($response->getBody()->getContents());
            }
        } catch (Throwable $e) {
            if ($e->getResponse()->getStatusCode() !== Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
                $this->log($e->getMessage(), 'error');

                if ($file->exists) {
                    $file->markFailure(
                        FileOperationName::DOWNLOAD,
                        'Failed to download file from Box',
                        $e->getMessage()
                    );
                }

                return false;
            }

            $this->log('File download from service completed.');
        }

        $path = $this->downstreamToTmpFile(null, $this->prepareFileName($file));

        $file->update(['size' => $this->getFileSize($path)]);

        return $path;
    }

    /**
     * @throws Throwable
     */
    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $downloadUrl = "{$this->apiUrl}/files/{$file->remote_service_file_id}/content";
        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'File id is not set.');

        $key = $this->prepareFileName($file);
        $uploadId = $this->createMultipartUpload($key, $file->mime_type);
        throw_unless($uploadId, CouldNotDownloadFile::class, 'Failed to initialize multi-part upload');

        $chunkSize = config('manager.chunk_size', 5) * 1024 * 1024;
        $chunkStart = 0;
        $partNumber = 1;
        $parts = [];

        try {
            while (true) {
                $chunkEnd = $chunkStart + $chunkSize;

                $response = $this->client->request('GET', $downloadUrl, [
                    'headers' => [
                        'Range'         => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                        'Authorization' => 'Bearer ' . $this->service->access_token,
                    ],
                ]);

                $this->httpStatus = $response->getStatusCode();

                if (in_array($this->httpStatus, [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED])) {
                    $file->markFailure(
                        FileOperationName::DOWNLOAD,
                        'Failed to download file from Box',
                    );

                    return false;
                }

                if ($response->getStatusCode() !== Response::HTTP_PARTIAL_CONTENT) {
                    break;
                }

                $parts[] = $this->uploadPart($key, $uploadId, $partNumber++, $response->getBody()->getContents());
                $chunkStart = $chunkEnd + 1;
            }
            $key = $this->completeMultipartUpload($key, $uploadId, $parts);
            $fileSize = $this->getFileSize($key);

            $file->update(['size' => $fileSize]);

            return $key;
        } catch (ClientException $e) {
            $this->httpStatus = $e->getResponse()->getStatusCode();

            if ($this->httpStatus === Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
                $key = $this->completeMultipartUpload($key, $uploadId, $parts);
                $fileSize = $this->getFileSize($key);

                $file->update(['size' => $fileSize]);

                return $key;
            }
        } catch (Exception|GuzzleException $e) {
            $this->log($e->getMessage(), 'error');
            $file->markFailure(
                FileOperationName::DOWNLOAD,
                'Failed to download file from Box',
                $e->getMessage()
            );
        }

        return false;
    }

    /**
     * @throws Throwable
     */
    public function downloadFromService(File $file): StreamedResponse|bool
    {
        if (empty($file->remote_service_file_id)) {
            throw new CouldNotDownloadFile('File id is not set.');
        }

        $downloadUrl = "{$this->apiUrl}/files/{$file->remote_service_file_id}/content";

        if (! filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
            throw new CouldNotDownloadFile('Download URL is not a valid URL.');
        }

        try {
            $chunkSizeBytes = config('manager.chunk_size', 5) * 1024 * 1024;
            $chunkStart = 0;

            // Create a streamed response
            $response =
                response()->streamDownload(function () use (&$chunkStart, $chunkSizeBytes, $downloadUrl) {
                    while (true) {
                        $chunkEnd = $chunkStart + $chunkSizeBytes;

                        try {
                            $response = $this->client->request('GET', $downloadUrl, [
                                'headers' => [
                                    'Range'         => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                                    'Authorization' => "Bearer {$this->service->access_token}",
                                ],
                            ]);

                            if ($response->getStatusCode() !== Response::HTTP_PARTIAL_CONTENT) {
                                break;
                            }

                            echo $response->getBody()->getContents();
                            $chunkStart = $chunkEnd + 1;
                        } catch (Exception $e) {
                            if ($e->getResponse()->getStatusCode() === Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
                                $this->log('File download from service completed');
                            } else {
                                $this->log($e->getMessage(), 'error');
                            }

                            break;
                        }
                    }
                }, $file->name);

            // Set headers for file download
            $response->headers->set('Content-Type', $file->mime_type);
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $file->slug . '.' . $file->extension . '"');
            $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
            $response->headers->set('Pragma', 'public');

            return $response;
        } catch (Exception $e) {
            if ($e->getResponse()->getStatusCode() !== Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
                $this->log($e->getMessage(), 'error');

                return false;
            }

            $this->log('File download from service completed');
        }

        return false;
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = data_get($request, 'folder_id');
        $type = data_get($request, 'site_drive_id');

        // Root entry
        if (empty($folderId) || $folderId === 'root') {
            $baseFolders = [
                ['id' => '0', 'name' => 'Folders', 'type' => 'folders'],
                ['id' => '1', 'name' => 'My Collections', 'type' => 'collections'],
            ];

            foreach ($baseFolders as $folder) {
                $folders[] = [
                    'id'          => $folder['id'],
                    'isDir'       => true,
                    'siteDriveId' => $folder['type'],
                    'name'        => $folder['name'],
                ];
            }

            return $folders;
        }

        $folderItems = match ($type) {
            'collections' => $this->getCollections(),
            'collection'  => $this->getItemsInFolder($folderId, 'collections'),
            default       => $this->getItemsInFolder((int) $folderId), // Folders
        };

        if ($type === 'collections') {
            $collections = [];

            foreach ($folderItems as $collection) {
                $collections[] = [
                    'id'          => $collection['id'],
                    'isDir'       => true,
                    'name'        => $collection['name'],
                    'siteDriveId' => 'collection',
                    ...$collection,
                ];
            }

            return $collections;
        }

        $folders = [];
        $files = [];

        if ($nestedFolders = data_get($folderItems, 'folders')) {
            foreach ($nestedFolders as $folder) {
                $folders[] = [
                    'id'    => $folder['id'],
                    'isDir' => true,
                    'name'  => $folder['name'],
                    ...$folder,
                ];
            }
        }

        if ($nestedFiles = data_get($folderItems, 'files')) {
            foreach ($nestedFiles as $file) {
                $files[] = [
                    'id'    => $file['id'],
                    'isDir' => false,
                    'name'  => $file['name'],
                    ...$file,
                ];
            }
        }

        return [...$folders, ...$files];
    }

    public function getCollections(): iterable
    {
        try {
            $response = $this->client->get("{$this->apiUrl}/collections", [
                'query' => [
                    'access_token' => $this->service->access_token,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            return data_get($body, 'entries');
        } catch (Exception $e) {
            $this->log('Error getting Box Collections' . $e->getMessage(), 'error');

            return [];
        }
    }

    public function listFolderSubFolders(?array $request): iterable
    {
        $items = [];
        $files = [];

        foreach (data_get($request, 'metadata', []) as $item) {
            $type = data_get($item, 'site_drive_id');

            switch ($type) {
                case 'collections': // Handle all collections
                    $collections = $this->getCollections();

                    foreach ($collections as $collection) {
                        $items = $this->getItemsInFolder(data_get($collection, 'id'), 'collections');
                        $files = [
                            ...$files,
                            ...data_get($items, 'files', []),
                            ...$this->processFolders(data_get($items, 'folders')),
                        ];
                    }

                    break;

                case 'collection': // Handle a collection
                    $items = [...$items, ...$this->getItemsInFolder(data_get($item, 'folder_id'), 'collections')];
                    $files = [
                        ...$files,
                        ...data_get($items, 'files', []),
                        ...$this->processFolders(data_get($items, 'folders')),
                    ];

                    break;

                case 'folders': // Handle all folders
                    return $this->index();

                default: // Handle a folder
                    $items = [...$items, ...$this->getItemsInFolder(data_get($item, 'folder_id'))];
                    $files = [
                        ...$files,
                        ...data_get($items, 'files', []),
                        ...$this->processFolders(data_get($items, 'folders')),
                    ];

                    break;
            }

            $identifier = data_get($item, 'folder_id') ?? data_get($collection, 'id') ?? now()->toString(); // @phpstan-ignore-line

            $this->dispatch($files, "{$type}-{$identifier}");
        }

        return $files;
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Box settings are required');
        abort_if(count(config('box.settings')) !== $settings->count(), 406, 'All Settings must be present');

        // Updated pattern to match client IDs with 10 to 15 digits at the start
        $clientIdPattern = '/^[a-zA-Z0-9]{32}$/';
        $clientSecretPattern = '/^[a-zA-Z0-9]{32}$/';

        $clientId = $settings->firstWhere('name', 'BOX_CLIENT_ID')?->payload ?? '';
        $clientSecret = $settings->firstWhere('name', 'BOX_CLIENT_SECRET')?->payload ?? '';

        abort_if(
            ! preg_match($clientIdPattern, $clientId),
            406,
            'Looks like your client ID format is invalid'
        );
        abort_if(
            ! preg_match($clientSecretPattern, $clientSecret),
            406,
            'Looks like your client secret format is invalid'
        );

        return true;
    }

    private function refreshAccessToken()
    {
        try {
            $response = $this->client->post(config('box.query_base_url') . '/oauth2/token', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $this->service->refresh_token,
                    'grant_type'    => 'refresh_token',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['access_token']) || isset($body['refresh_token']) || isset($body['expires_in'])) {
                $this->service->update([
                    'access_token'  => $body['access_token'],
                    'refresh_token' => $body['refresh_token'],
                    'expires'       => now()->addSeconds($body['expires_in'])->getTimestamp(),
                ]);
            }
        } catch (Exception $e) {
            $this->log('Failed to refresh access token: ' . $e->getMessage(), 'error');
            $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
        }
    }

    private function handleTokenExpiration()
    {
        if ($this->isAccessTokenExpired()) {
            $this->refreshAccessToken();
        }
    }
}
