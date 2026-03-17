<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nuxeo;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Collection as SupportCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;

// Docs: https://doc.nuxeo.com/nxdoc/rest-api/
class Nuxeo extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata, HasSettings, IsTestable
{
    public ?string $username;

    public ?string $password;

    public ?string $server;

    public ?string $queryBase;

    public function initialize(): void
    {
        $settings = $this->getSettings();
        $this->username = data_get($settings, 'username');
        $this->password = data_get($settings, 'password');
        $this->server = data_get($settings, 'server');
        $this->queryBase = 'https://' . $this->server . '.com/nuxeo';
    }

    public function getSettings(?array $customKeys = []): array
    {
        $settings = parent::getSettings($customKeys);
        $username = $settings['NUXEO_USERNAME'] ?? config('nuxeo.username');
        $password = $settings['NUXEO_PASSWORD'] ?? config('nuxeo.password');
        $server = $settings['NUXEO_SERVER_SUBDOMAIN'] ?? config('nuxeo.server');

        return compact('username', 'password', 'server');
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $authUrl = Path::join(config('app.url'), 'nuxeo-redirect');

        if (isset($settings) && $settings->count()) {
            $authUrl .= '?' . http_build_query([
                'state' => json_encode(['settings' => $this->settings->pluck('id')?->toArray()]),
            ]);
        }

        $this->redirectTo($authUrl);
    }

    public function getUser(): ?UserDTO
    {
        try {
            $response = Http::timeout(config('queue.timeout'))->withOptions(['verify' => false]) // Todo: Fix certificate issue
                ->withBasicAuth($this->username, $this->password)
                ->get($this->queryBase . '/api/v1/me/')->throw();

            $properties = data_get($response->collect(), 'properties');

            $email = data_get($properties, 'email');
            $username = data_get($properties, 'username');
            $firstName = data_get($properties, 'firstName');

            return new UserDTO([
                'email'     => $email ?? $username ?? $firstName,
                'username'  => $username,
                'firstName' => $firstName,
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return new UserDTO;
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        return new TokenDTO([
            'username' => $this->username,
            'password' => $this->password,
            'created'  => now(),
        ]);
    }

    public function listFolderContent(?array $request): iterable
    {
        $currentPageIndex = 0;
        $folders = collect();

        while (true) {
            $result = $this->getItemsFromPath(data_get($request, 'folder_id'), $currentPageIndex);
            $entries = data_get($result, 'entries', []);

            if (filled($entries)) {
                $newFolders = collect($entries)->filter(fn ($entry) => data_get($entry, 'type') != 'Asset');
                $folders = $folders->merge($newFolders);
            }

            if (! data_get($result, 'isNextPageAvailable', false)) {
                break;
            }

            $currentPageIndex += 1;
        }

        return $folders->map(fn ($folder) => [
            'id'    => data_get($folder, 'path'),
            'isDir' => true,
            'name'  => Str::afterLast(data_get($folder, 'path'), '/'),
        ])->values();
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        try {
            $response = Http::timeout(config('queue.timeout'))
                ->withOptions(['verify' => false])
                ->withBasicAuth($this->username, $this->password)
                ->get("{$this->queryBase}/nxfile/default/{$file->remote_service_file_id}");

            if ($response->failed()) {
                $this->log("Failed to download file. File Id: {$file->id}", 'error');

                return false;
            }

            $path = $this->storeDataAsFile($response->body(), $this->prepareFileName($file));
        } catch (Exception $e) {
            $this->log("Error getting downloading FileId {$file->id}. Error: {$e->getMessage()}", 'error');

            return false;
        }

        $file->update(['size' => $this->getFileSize($path)]);

        return $path;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');
        throw_unless($this->queryBase, CouldNotDownloadFile::class, 'Query base is not set');

        $downloadUrl = $this->queryBase . '/nxfile/default/' . $file->remote_service_file_id;

        try {
            $key = $this->prepareFileName($file);
            $uploadId = $this->createMultipartUpload($key, $file->mime_type);

            throw_unless($uploadId, CouldNotDownloadFile::class, 'Failed to initialize multi-part upload');

            $chunkSizeBytes = config('manager.chunk_size', 5) * 1024 * 1024;
            $partNumber = 1;
            $parts = [];

            $response = Http::timeout(config('queue.timeout'))->withOptions(['verify' => false])
                ->withBasicAuth($this->username, $this->password)
                ->withHeaders(['Accept' => '*/*'])
                ->get($downloadUrl, ['sink' => true])
                ->throw();

            $body = $response->getBody();

            if ($response->successful()) {
                while (! $body->eof()) {
                    $chunk = $body->read($chunkSizeBytes);
                    $parts[] = $this->uploadPart($key, $uploadId, $partNumber++, $chunk);
                }

                return $this->completeMultipartUpload($key, $uploadId, $parts);
            }
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return false;
    }

    public function downloadFromService(File $file): StreamedResponse|BinaryFileResponse|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $downloadUrl = "{$this->queryBase}/nxfile/default/{$file->remote_service_file_id}";

        try {
            $response = response()
                ->streamDownload(function () use ($downloadUrl) {
                    try {
                        $response = Http::timeout(config('queue.timeout'))
                            ->withOptions(['verify' => false])
                            ->withBasicAuth($this->username, $this->password)
                            ->get($downloadUrl);

                        echo $response->body();
                    } catch (Exception $e) {
                        $this->log($e->getMessage(), 'error');
                    }
                }, $file->name);

            // Set headers for file download
            $response->headers->set('Content-Type', $file->mime_type);
            $response->headers->set('Content-Disposition', "attachment; filename={$file->slug}.{$file->extension}");
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
        if (! $url = data_get($file, 'thumbnail')) {
            $this->log('Thumbnail URL not found');

            return null;
        }

        try {
            $response = Http::timeout(config('queue.timeout'))
                ->withOptions(['verify' => false])
                ->withBasicAuth($this->username, $this->password)
                ->get($url)
                ->throw();
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return null;
        }

        $filename = $file instanceof File
            ? $this->prepareFileName($file)
            : str()->random(6) . '.jpg';

        return $this->storeDataAsFile($response->body(), $filename, 'thumbnails');
    }

    public function getMetadataAttributes(?array $properties): array
    {
        return data_get($properties, 'properties', []);
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $fileName = data_get($file, 'properties.file:content.name');
        $mimeType = data_get($file, 'properties.file:content.mime-type');
        $extension = $this->getFileExtensionFromFileName($fileName);

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => $this->uniqueFileId($file, 'uid'),
            'name'                   => pathinfo($fileName, PATHINFO_FILENAME),
            'thumbnail'              => data_get($file, 'contextParameters.thumbnail.url'),
            'mime_type'              => $mimeType,
            'type'                   => $this->getFileTypeFromExtension($extension),
            'extension'              => $extension,
            'slug'                   => str()->slug(pathinfo($fileName, PATHINFO_FILENAME)),
            'created_time'           => data_get($file, 'properties.dc:created')
                ? Carbon::parse(data_get($file, 'properties.dc:created'))->format('Y-m-d H:i:s')
                : null,
            'modified_time' => data_get($file, 'lastModified')
                ? Carbon::parse(data_get($file, 'lastModified'))->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Nuxeo settings are required');
        abort_if(count(config('nuxeo.settings')) !== $settings->count(), 406, 'All Settings must be present');

        return true;
    }

    public function paginate(?array $request = []): void
    {
        $folders = data_get($request, 'folder_ids', []);

        if (empty($folders)) {
            // Handle root sync
            $this->getFolderFilesAndDispatchJobs();

            return;
        }

        foreach ($folders as $folderPath) {
            $this->getFolderFilesAndDispatchJobs($folderPath);
        }
    }

    public function getFolderFilesAndDispatchJobs($path = null, $currentPageIndex = 0): void
    {
        while (true) {
            $result = $this->getItemsFromPath($path, $currentPageIndex);
            $entries = data_get($result, 'entries', []);

            if (! $result || empty($entries)) {
                break;
            }

            $files = [];

            foreach ($entries as $entry) {
                $type = data_get($entry, 'type');

                if ($type == 'Asset' && in_array(pathinfo(data_get($entry, 'path'), PATHINFO_EXTENSION), config('manager.meta.file_extensions'))) {
                    $files[] = $entry;
                } elseif ($type != 'Asset') {
                    $this->getFolderFilesAndDispatchJobs(data_get($entry, 'path'));
                }
            }

            if (filled($files)) {
                $this->dispatch($files, $path);
            }

            if (! data_get($result, 'isNextPageAvailable', false)) {
                break;
            }

            $currentPageIndex += 1;
        }
    }

    public function getItemsFromPath($path = null, $currentPageIndex = 0): bool|SupportCollection
    {
        if ($path == 'root') {
            $path = null;
        }

        try {
            $response = Http::timeout(config('queue.timeout'))->withOptions(['verify' => false]) // Todo: Fix certificate issue
                ->withBasicAuth($this->username, $this->password)
                ->withHeaders(['X-NXDocumentProperties' => '*'])
                ->withQueryParameters([
                    'enrichers.document' => 'blob,thumbnail',
                    'pageSize'           => config('nuxeo.page_size'), // 2000 max
                    'currentPageIndex'   => $currentPageIndex,
                ])
                ->get($this->queryBase . '/api/v1/path' . $path . '/@children');

            if ($response->failed()) {
                $this->log('Failed to retrieve data for path: ' . $path, 'error');

                return false;
            }

            return $response->collect();
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return false;
        }
    }
}
