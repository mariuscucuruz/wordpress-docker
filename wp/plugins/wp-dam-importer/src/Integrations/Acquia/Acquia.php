<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Acquia;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Support\LazyCollection;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\SourceIntegration;
use Symfony\Component\HttpFoundation\Response;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use Symfony\Component\HttpFoundation\File\File as FileUpload;

class Acquia extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata, HasSettings, IsTestable
{
    // Docs: https://docs.acquia.com/acquia-dam/api-v2

    public Client $client;

    public ?string $bearerToken;

    public function initialize()
    {
        $this->client = new Client;
        $settings = $this->getSettings();
        $this->bearerToken = $settings['bearerToken'];
    }

    public function getSettings($customKeys = null): array
    {
        $settings = parent::getSettings($customKeys);
        $bearerToken = $settings['ACQUIA_BEARER_TOKEN'] ?? config('acquia.bearer_token');

        return compact('bearerToken');
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $authUrl = Path::join(config('app.url'), 'acquia-redirect');
        $state = $this->generateRedirectOauthState();

        $authUrl .= '?' . http_build_query(compact('state'));

        $this->redirectTo($authUrl);
    }

    public function getUser(): ?UserDTO
    {
        try {
            $response = $this->client->get(config('acquia.query_base_url') . '/user', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->bearerToken,
                    'Accept'        => 'application/json',
                ],
            ]);
            $body = json_decode($response->getBody()->getContents(), true);

            return new UserDTO([
                'email'   => data_get($body, 'email') ?? data_get($body, 'username'),
                'name'    => data_get($body, 'first_name') . data_get($body, 'last_name'),
                'user_id' => data_get($body, 'uuid'),
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return new UserDTO;
        }
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        return new TokenDTO([
            'access_token' => $this->bearerToken,
            'expires'      => null,
            'created'      => now(),
        ]);
    }

    /**
     * @deprecated
     */
    public function getFilesInFolder($folderId = null): iterable
    {
        try {
            return LazyCollection::make(function ($scrollId = null) use ($folderId) {
                $response = $this->client->get(config('acquia.query_base_url') . '/assets/search', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->bearerToken,
                        'Accept'        => 'application/json',
                    ],
                    'query' => [
                        'expand'    => 'thumbnails, file_properties, metadata',
                        'scroll'    => 'true',
                        'scroll_id' => $scrollId,
                        'limit'     => 100, // max
                        'query'     => $folderId,
                    ],
                ]);

                // Duplicate code
                $data = json_decode($response->getBody()->getContents(), true);
                $items = data_get($data, 'items');
                $scrollId = data_get($data, 'scroll_id');
                $filteredItems = [];

                foreach ($items as $file) {
                    $fileName = data_get($file, 'filename');
                    $ext = $this->getFileExtensionFromFileName($fileName);

                    if (in_array($ext, config('manager.meta.file_extensions'))) {
                        $filteredItems[] = $file;
                    }
                }

                yield from $filteredItems;

                if ($scrollId) {
                    yield from $this->getPaginatedItems($scrollId);
                }
            });
        } catch (Exception $e) {
            $this->checkAndHandleServiceAuthorisation();
            $this->log($e->getMessage(), 'error');
        }

        return [];
    }

    /**
     * @deprecated
     */
    public function getPaginatedItems($scrollId): iterable
    {
        while (true) {
            try {
                $response = $this->client->get(config('acquia.query_base_url') . '/assets/search/scroll', [
                    'headers' => [
                        'Authorization' => "Bearer {$this->bearerToken}",
                        'Accept'        => 'application/json',
                    ],
                    'query' => [
                        'expand'    => 'thumbnails, file_properties, metadata',
                        'scroll_id' => $scrollId,
                        'limit'     => 100, // max
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                $items = data_get($data, 'items');
                $scrollId = data_get($data, 'scroll_id');
                $filteredItems = [];

                foreach ($items as $file) {
                    $fileName = data_get($file, 'filename');
                    $ext = $this->getFileExtensionFromFileName($fileName);

                    if (in_array($ext, config('manager.meta.file_extensions'))) {
                        $filteredItems[] = $file;
                    }
                }

                yield from $filteredItems;
            } catch (Exception|GuzzleException $e) {
                $this->checkAndHandleServiceAuthorisation();
                $this->log($e->getMessage(), 'error');

                break;
            }

            if (! $scrollId || ! $items) {
                break;
            }
        }
    }

    public function getThumbnailUrl($fileID)
    {
        try {
            $response = $this->client->get(config('acquia.query_base_url') . '/assets/' . $fileID, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->bearerToken,
                    'Accept'        => 'application/json',
                ],
                'query' => [
                    'expand' => 'thumbnails',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $thumbnails = data_get($data, 'thumbnails');

            return data_get($thumbnails, '2048px.url') ?? data_get(last($thumbnails), 'url');
        } catch (Exception|GuzzleException $e) {
            $this->log($e->getMessage(), 'error');
        }

        return null;
    }

    public function getDownloadUrl($fileID)
    {
        try {
            $response = $this->client->get(config('acquia.query_base_url') . '/assets/' . $fileID, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->bearerToken,
                    'Accept'        => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return data_get($data, '_links.download');
        } catch (Exception|GuzzleException $e) {
            $this->log($e->getMessage(), 'error');
        }

        return null;
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $tempFilePath = tempnam(sys_get_temp_dir(), config('acquia.name') . '_');

        throw_unless(
            $tempFilePath,
            CouldNotDownloadFile::class,
            'Temporary file not found!'
        );

        $fp = fopen($tempFilePath, 'w');

        // Download in 5 MB chunks:
        $chunkSizeBytes = config('manager.chunk_size', 5) * 1048576;
        $chunkStart = 0;

        try {
            while (true) {
                $chunkEnd = $chunkStart + $chunkSizeBytes;
                $response = $this->client->request('GET', $this->getDownloadUrl($file->remote_service_file_id), [
                    'headers' => [
                        'Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                    ],
                ]);

                $this->httpStatus = $response->getStatusCode();

                if (in_array($this->httpStatus, [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED])) {
                    $this->cleanupTemporaryFile($tempFilePath, $fp);

                    return false;
                }

                if ($this->httpStatus == Response::HTTP_PARTIAL_CONTENT) {
                    $chunkStart = $chunkEnd + 1;
                    fwrite($fp, $response->getBody()->getContents());
                } else {
                    break;
                }
            }
        } catch (Exception $e) {
            if ($e->getResponse()->getStatusCode() == Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
                $this->log('File download from service completed');
            } else {
                $this->log("Could not download file: {$e->getMessage()}", 'error', null, $e->getTrace());

                $this->cleanupTemporaryFile($tempFilePath, $fp);

                return false;
            }
        }

        $path = $this->storage->putFileAs(
            $this->getStoragePathForFile($file),
            new FileUpload($tempFilePath),
            $this->prepareFileName($file)
        );

        $fileSize = $this->getFileSize($path);

        $file->update(['size' => $fileSize]);

        $this->cleanupTemporaryFile($tempFilePath, $fp);

        return $path;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $downloadUrl = $this->getDownloadUrl($file->remote_service_file_id);

        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'Download URL is not set.');

        return $this->handleMultipartDownload($file, $downloadUrl);
    }

    /**
     * @throws \Throwable
     */
    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $downloadUrl = $this->getDownloadUrl($file->remote_service_file_id);

        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'Download URL is not set.');

        return $this->handleDownloadFromService($file, $downloadUrl);
    }

    public function uploadThumbnail(mixed $file): ?string
    {
        $url = data_get($file, 'thumbnail');

        if (empty($url)) {
            return null;
        }

        $thumbnail = $this->getThumbnail($url);

        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            $file['id'],
            $file['remote_service_file_id'] . '.jpg'
        );

        $this->storage->put($thumbnailPath, $thumbnail ?? '');

        return $thumbnailPath;
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = $request['folder_id'] ?? null;
        $drivePath = $request['site_drive_id'] ?? null;
        $folders = [];

        if (! $folderId || $folderId == 'root') {
            $baseFolders = ['Categories']; // Consider adding Asset Groups, Channels etc.

            foreach ($baseFolders as $folder) {
                $folders[] = [
                    'id'          => $folder,
                    'isDir'       => true,
                    'siteDriveId' => strtolower($folder),
                    'name'        => $folder,
                ];
            }

            return $folders;
        }

        // If query is base folder then construct query. Else query is given.
        $queryUrl = $drivePath === 'categories'
            ? Path::join(config('acquia.query_base_url'), $drivePath)
            : $drivePath;

        try {
            $response = $this->client->get($queryUrl, [
                'headers' => [
                    'Authorization' => "Bearer {$this->bearerToken}",
                    'Accept'        => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $categories = data_get($data, 'items');

            if ($categories) {
                foreach ($categories as $subCategory) {
                    $folders[] = [
                        'id'          => data_get($subCategory, 'id'),
                        'isDir'       => true,
                        'siteDriveId' => data_get($subCategory, '_links.categories'), // children categories query path
                        'name'        => data_get($subCategory, 'name'),
                    ];
                }
            }

            return $folders;
        } catch (Exception $e) {
            $this->checkAndHandleServiceAuthorisation();
            $this->log($e->getMessage(), 'error');

            return [];
        }
    }

    public function listFolderSubFolders(?array $request)
    {
        $this->getFolderFilesAndDispatchJobs($request);
    }

    public function getThumbnail($fileUrl): ?string
    {
        try {
            $client = new Client;
            $response = $client->request('GET', $fileUrl);
            $this->httpStatus = $response->getStatusCode();

            if ($response->getStatusCode() == 200) {
                return $response->getBody()->getContents();
            }
        } catch (Exception $e) {
            $this->httpStatus = $e->getCode();
            $this->log($e->getMessage(), 'error');
        }

        return null;
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $thumbnailPath = last($file['thumbnails'])['url'];
        $name = pathinfo($file['filename'], PATHINFO_FILENAME);
        $extension = $this->getFileExtensionFromFileName($file['filename']);
        $type = data_get($file, 'file_properties.format_type') ?: $this->getFileTypeFromExtension($extension);
        $mimeType = $this->getMimeTypeOrExtension($extension) ?: Path::join($type, strtolower($extension));
        $fileSize = data_get($file, 'file_properties.size_in_kbytes');
        $duration = data_get($file, 'file_properties.video_properties.duration');

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => data_get($file, 'id'),
            'name'                   => $name,
            'thumbnail'              => $thumbnailPath,
            'mime_type'              => $mimeType,
            'type'                   => $type,
            'extension'              => strtolower($extension),
            'size'                   => isset($fileSize) ? $fileSize * 1000 : null,
            'duration'               => isset($duration) ? $duration * 1000 : null,
            'slug'                   => str()->slug(pathinfo($file['filename'], PATHINFO_FILENAME)),
            'created_time'           => data_get($file, 'created_date')
                ? Carbon::parse($file['created_date'])->format('Y-m-d H:i:s')
                : null,
            'modified_time' => isset($file['last_update_date'])
                ? Carbon::parse($file['last_update_date'])->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public function getMetadataAttributes(?array $properties): array
    {
        return data_get($properties, 'metadata.fields', []);
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Acquia settings are required');
        abort_if(count(config('acquia.settings')) !== $settings->count(), 406, 'All Settings must be present');

        // Updated pattern to match client IDs with 10 to 15 digits at the start
        $bearerTokenPattern = '/^[a-zA-Z0-9]{32,}$/'; // max: 32?// max: 32?

        $bearerToken = $settings->firstWhere('name', 'ACQUIA_BEARER_TOKEN')?->payload ?? '';
        $token = explode('/', $bearerToken)[1];

        abort_if(! preg_match($bearerTokenPattern, $token), 406, 'Looks like your bearer token format is invalid');

        return true;
    }

    public function isServiceAuthorised(): bool
    {
        $response = Http::timeout(config('queue.timeout'))
            ->withToken($this->bearerToken)
            ->get(config('acquia.query_base_url') . '/assets/search', [
                'limit' => 1,
            ]);

        if ($response->failed() || empty(data_get($response->json(), 'items.0.id'))) {
            return false;
        }

        return true;
    }

    public function paginate(array $request = []): void
    {
        if (! $request) {
            $this->getFilesAndDispatchJob();

            return;
        }

        $this->getFolderFilesAndDispatchJobs($request);
    }

    public function getFilesAndDispatchJob($folderId = null, $scrollId = null): void
    {
        try {
            $url = config('acquia.query_base_url') . '/assets/search';
            $query = [
                'expand' => 'thumbnails,file_properties,metadata',
                'scroll' => 'true',
                'limit'  => config('acquia.per_page'), // max
                'query'  => $folderId,
            ];

            if ($scrollId) {
                $url = config('acquia.query_base_url') . '/assets/search/scroll';
                $query['scroll_id'] = $scrollId;
            }

            $response = $this->getResponse($url, $query);

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $items = data_get($data, 'items');

            // scroll id will keep returning even if there are no items
            if (empty($items)) {
                return;
            }

            $scrollId = data_get($data, 'scroll_id');
            $filteredItems = [];

            foreach ($items as $file) {
                $fileName = data_get($file, 'filename');

                $ext = $this->getFileExtensionFromFileName($fileName);

                if (in_array($ext, config('manager.meta.file_extensions'))) {
                    $filteredItems[] = $file;
                }
            }

            $this->dispatch($filteredItems, $folderId ?? 'root');

            if ($scrollId) {
                $this->getFilesAndDispatchJob($folderId ?? 'root', $scrollId);
            }
        } catch (Exception $e) {
            $this->checkAndHandleServiceAuthorisation();
            $this->log($e->getMessage(), 'error');
        }
    }

    public function getResponse($url, $query): ResponseInterface
    {
        return $this->client->get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->bearerToken,
                'Accept'        => 'application/json',
            ],
            'query' => $query,
        ]);
    }

    private function getFolderFilesAndDispatchJobs(array $request): void
    {
        $folders = data_get($request, 'metadata');

        foreach ($folders as $folder) {
            $category = data_get($folder, 'folder_name');

            // Handle if sync 'root' or 'Categories' folder. Sync All.
            if (! $category || $category === 'root' || $category === 'Categories') {
                $this->getFilesAndDispatchJob();

                return;
            }

            $this->getFilesAndDispatchJob('cat:(' . $category . ')');
        }
    }
}
