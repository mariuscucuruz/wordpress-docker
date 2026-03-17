<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Googledrive;

use Exception;
use Generator;
use Throwable;
use Google\Client;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use Google\Service\Drive;
use MariusCucuruz\DAMImporter\Support\DataObject;
use Illuminate\Support\Arr;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Support\LazyCollection;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Pagination\PaginationType;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use MariusCucuruz\DAMImporter\Pagination\PaginatedResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use Symfony\Component\HttpFoundation\File\File as FileUpload;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;

class Googledrive extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata, HasSettings, IsTestable
{
    private Client $client;

    private Drive $drive;

    protected function getPaginationType(): PaginationType
    {
        return PaginationType::Token;
    }

    protected function getRootFolders(): array
    {
        return [['id' => 'root']];
    }

    protected function fetchPage(?string $folderId, mixed $cursor, array $folderMeta = []): PaginatedResponse
    {
        $sharedWithMe = data_get($folderMeta, 'sharedWithMe', false);
        $extensions = array_map(fn ($ext) => "fileExtension contains '{$ext}'", config('manager.meta.file_extensions'));

        $q = $sharedWithMe
            ? '(' . sprintf('((%s) or mimeType="application/vnd.google-apps.folder")', implode(' or ', $extensions)) . ') and sharedWithMe and trashed = false'
            : '(' . sprintf('((%s) or mimeType="application/vnd.google-apps.folder") and %s', implode(' or ', $extensions),
                "'{$folderId}' in parents") . ') and trashed = false';

        try {
            $response = $this->getResponse([
                ...config('googledrive.default_params', []),
                'q'         => $q,
                'pageToken' => $cursor,
            ]);

            $files = data_get($response, 'files', []);
            $subfolders = [];
            $items = [];

            foreach ($files as $item) {
                if (data_get($item, 'mimeType') === 'application/vnd.google-apps.folder') {
                    $subfolders[] = ['id' => $item['id'], 'name' => $item['name']];
                } else {
                    $items[] = $item;
                }
            }

            $nextCursor = data_get($response, 'nextPageToken');

            return new PaginatedResponse($items, $subfolders, $nextCursor);
        } catch (Exception $e) {
            $this->httpStatus = $e->getCode();
            logger()->error($e->getMessage());

            if ($this->httpStatus === 401) {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }

            return new PaginatedResponse([], [], null);
        }
    }

    protected function transformItems(array $items): array
    {
        return collect($items)->map(function ($item) {
            if (is_array($item)) {
                return $item;
            }

            /** @var DriveFile $item */
            return [
                'id'                 => $item->getId(),
                'file_id'            => $item->getId(),
                'md5Checksum'        => $item->getMd5Checksum(),
                'name'               => "{$item->getName()}.{$item->getFileExtension()}",
                'mimeType'           => $item->getMimeType(),
                'thumbnailLink'      => $item->getThumbnailLink(),
                'videoMediaMetadata' => DataObject::toArray($item->getVideoMediaMetadata()),
                'imageMediaMetadata' => DataObject::toArray($item->getImageMediaMetadata()),
                'metadata'           => [
                    'share' => DataObject::toArray($item->getLinkShareMetadata()),
                    'image' => DataObject::toArray($item->getImageMediaMetadata()),
                    'video' => DataObject::toArray($item->getVideoMediaMetadata()),
                ],
                'modifiedTime' => $item->getModifiedTime(),
                'createdTime'  => $item->getCreatedTime(),
                'user_id'      => $item->getOwners()[0]->getDisplayName(),
                'user_name'    => $item->getOwners()[0]->getEmailAddress(),
                'service_id'   => $this->service->id,
                'service_name' => self::getServiceName(),
            ];
        })->toArray();
    }

    protected function filterSupportedExtensions(array $items): array
    {
        // Extension filtering is done in the query for Google Drive
        return $items;
    }

    public function paginate(array $request = []): void
    {
        if (! isset($request['folder_ids'])) {
            $this->getAllFiles();

            return;
        }

        foreach ($request['folder_ids'] as $folderId) {
            switch ($folderId) {
                case 'root':
                    // Skip processing for 'root'
                    break;

                case 'SharedWithMe':
                    $this->getFolderFiles('SharedWithMe', 'SharedWithMe', true);

                    break;

                case 'SharedDrives':
                    $drives = $this->getSharedDrives();

                    foreach ($drives as $drive) {
                        $this->getFolderFiles($drive['id']);
                    }

                    break;

                default:
                    $this->getFolderFiles($folderId);

                    break;
            }
        }
    }

    public function getFolderFiles(?string $folderId = 'root', $folderName = null, $sharedWithMe = false, $nextPageToken = null): void
    {
        if ($folderId === 'MyDrive') {
            $folderId = 'root';
        }

        $folderMeta = ['sharedWithMe' => $sharedWithMe, 'name' => $folderName];

        // For initial call, use base trait pagination
        if ($nextPageToken === null) {
            $this->paginateFolderWithName($folderId, $folderMeta, $folderName);

            return;
        }

        // For recursive calls with token, use original logic for backward compatibility
        $extensions = array_map(fn ($ext) => "fileExtension contains '{$ext}'", config('manager.meta.file_extensions'));

        $q = $sharedWithMe
            ? '(' . sprintf('((%s) or mimeType="application/vnd.google-apps.folder")', implode(' or ', $extensions)) . ') and sharedWithMe and trashed = false'
            : '(' . sprintf('((%s) or mimeType="application/vnd.google-apps.folder") and %s', implode(' or ', $extensions),
                "'{$folderId}' in parents") . ') and trashed = false';

        try {
            $response = $this->getResponse([
                ...config('googledrive.default_params', []),
                ...compact('q', []),
                'pageToken' => $nextPageToken,
            ]);
            $files = data_get($response, 'files');
            $filesOnly = [];

            foreach ($files as $item) {
                if (data_get($item, 'mimeType') === 'application/vnd.google-apps.folder') {
                    $this->getFolderFiles($item['id'], $item['name']);
                } else {
                    if (is_array($item)) {
                        $filesOnly[] = $item;

                        continue;
                    }

                    /** @var DriveFile $item */
                    $filesOnly[] = [
                        'id'                 => $item->getId(),
                        'file_id'            => $item->getId(),
                        'md5Checksum'        => $item->getMd5Checksum(),
                        'name'               => "{$item->getName()}.{$item->getFileExtension()}",
                        'mimeType'           => $item->getMimeType(),
                        'thumbnailLink'      => $item->getThumbnailLink(),
                        'videoMediaMetadata' => DataObject::toArray($item->getVideoMediaMetadata()),
                        'imageMediaMetadata' => DataObject::toArray($item->getImageMediaMetadata()),
                        'metadata'           => [
                            'share' => DataObject::toArray($item->getLinkShareMetadata()),
                            'image' => DataObject::toArray($item->getImageMediaMetadata()),
                            'video' => DataObject::toArray($item->getVideoMediaMetadata()),
                        ],
                        'modifiedTime' => $item->getModifiedTime(),
                        'createdTime'  => $item->getCreatedTime(),
                        'user_id'      => $item->getOwners()[0]->getDisplayName(),
                        'user_name'    => $item->getOwners()[0]->getEmailAddress(),
                        'service_id'   => $this->service->id,
                        'service_name' => self::getServiceName(),
                    ];
                }
            }
            $nextPageToken = data_get($response, 'nextPageToken');
            $importGroupName = $folderName ?? $folderId;
            $this->dispatch($filesOnly, $importGroupName);

            if ($nextPageToken) {
                $this->getFolderFiles($folderId, $folderName, $sharedWithMe, $nextPageToken);
            }
        } catch (Exception $e) {
            logger()->error($e->getMessage());
            $this->httpStatus = $e->getCode();

            if ($this->httpStatus === 401) {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }
        }
    }

    protected function paginateFolderWithName(?string $folderId, array $folderMeta, ?string $folderName): void
    {
        $cursor = $this->getInitialCursor();
        $pageCount = 0;

        do {
            $response = $this->fetchPage($folderId, $cursor, $folderMeta);

            foreach ($response->subfolders as $subfolder) {
                $this->paginateFolderWithName(
                    data_get($subfolder, 'id'),
                    $subfolder,
                    data_get($subfolder, 'name')
                );
            }

            $items = $this->applyDateFilter($response->items);
            $items = $this->transformItems($items);
            $items = $this->filterSupportedExtensions($items);

            if (filled($items)) {
                $importGroupName = $folderName ?? $folderId;
                $this->dispatch($items, $importGroupName);
            }

            $cursor = $this->advanceCursor($cursor, $response);
            $pageCount++;
        } while ($cursor !== null);
    }

    public function getResponse(array $params)
    {
        return $this->drive->files->listFiles($params);
    }

    public function getAllFiles(array $params = [], $nextPageToken = null): void
    {
        $this->initialize();

        $videoConditions = array_map(fn ($ext) => "fileExtension contains '{$ext}'", config('manager.meta.file_extensions'));
        $q = '(' . implode(' or ', $videoConditions) . ') and trashed = false';

        try {
            $response = $this->getResponse([...[...config('googledrive.default_params'), ...[...$params, 'q' => $q]], 'pageToken' => $nextPageToken]);
            $files = data_get($response, 'files');

            $nextPageToken = data_get($response, 'nextPageToken');
            $this->dispatch($files, 'root');

            if ($nextPageToken) {
                $this->getAllFiles($params, $nextPageToken);
            }
        } catch (Exception $e) {
            $this->httpStatus = $e->getCode();
            logger()->error($e->getMessage());
        }
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $this->settings = $settings;

        $state = $this->generateRedirectOauthState();

        $this->client->setState($state);

        $authUrl = $this->client->createAuthUrl();

        throw_unless(
            $authUrl,
            CouldNotInitializePackage::class,
            'GoogleDrive settings are required!'
        );

        $this->redirectTo($authUrl);
    }

    public function listFiles($params): LazyCollection
    {
        return LazyCollection::make(fn () => yield from $this->listFilesRecursive($params));
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = $request['folder_id'] ?? null;
        $driveType = $request['site_drive_id'] ?? null;

        if (! $folderId || $folderId == 'root') {
            $folders = [];
            $baseFolders = ['MyDrive', 'SharedDrives', 'SharedWithMe'];

            foreach ($baseFolders as $folder) {
                $folders[] = [
                    'id'          => $folder,
                    'isDir'       => true,
                    'siteDriveId' => $folder,
                    'name'        => $folder,
                ];
            }

            return $folders;
        }

        return match ($driveType) {
            'SharedDrives' => $this->getSharedDrives(),
            'SharedWithMe' => $this->getDriveItems($folderId, true),
            default        => $this->getDriveItems($folderId),
        };
    }

    public function getDriveItems($folderId = null, $sharedWithMe = false): array
    {
        if ($folderId == 'MyDrive' || ! $folderId) {
            $folderId = 'root';
        }

        $folderMimeType = config('googledrive.mime_types.folder');
        $allConditions = [...$this->getAssetsUsingExtensions(), "mimeType='{$folderMimeType}'"];

        $q = $sharedWithMe
            ? '(' . implode(' or ', $allConditions) . ') and sharedWithMe and trashed = false'
            : sprintf('(%s) and %s',
                implode(' or ', $allConditions),
                $folderId ?
                    "'{$folderId}' in parents" : '"root" in parents') . ' and trashed = false';

        $files = $this->listFiles(compact('q'));
        $newFiles = [];

        foreach ($files as $file) {
            $newFiles[] = [
                'id'           => $file['id'],
                'isDir'        => $file['mimeType'] === $folderMimeType,
                'siteDriveId'  => 'DriveItem',
                'name'         => $file['name'],
                'thumbnailUrl' => $file['thumbnailLink'] ?? '',
                ...json_decode(json_encode($file), true),
            ];
        }

        return $newFiles;
    }

    public function getSharedDrives(): array
    {
        try {
            $drives = $this->drive->drives->listDrives()['drives'] ?? null;
            $folders = [];

            if ($drives) {
                foreach ($drives as $drive) {
                    $folders[] = [
                        'id'          => $drive['id'],
                        'isDir'       => true,
                        'siteDriveId' => 'DriveItem',
                        'name'        => $drive['name'],
                    ];
                }
            }

            return $folders;
        } catch (Exception $e) {
            $this->httpStatus = $e->getCode();
            $this->log($e->getMessage(), 'error');

            return [];
        }
    }

    public function listFolderSubFolders(?array $request): array
    {
        $files = [];

        if (isset($request['folder_ids'])) {
            foreach ($request['folder_ids'] as $folderId) {
                if ($folderId !== 'root') {
                    if ($folderId == 'SharedWithMe') {
                        $files = [...$files, ...$this->syncAllSharedWithMe()];
                    } elseif ($folderId == 'SharedDrives') {
                        $drives = $this->getSharedDrives();

                        foreach ($drives as $drive) {
                            $files = [...$files, ...$this->getFilesInFolder($drive['id'])];
                        }
                    } else {
                        $files = [...$files, ...$this->getFilesInFolder($folderId)];
                    }
                }
            }
        }

        if (isset($request['file_ids'])) {
            foreach ($request['file_ids'] as $fileId) {
                try {
                    $file = $this->drive->files->get($fileId, ['fields' => '*']);
                    $files[] = $file;
                } catch (Exception $e) {
                    $this->httpStatus = $e->getCode();
                    $this->log($e->getMessage(), 'error');
                }
            }
        }

        return $files;
    }

    public function syncAllSharedWithMe(): array
    {
        $files = [];

        if (($items = $this->getDriveItems('SharedWithMe', true))) {
            foreach ($items as $item) {
                if ($item['mimeType'] === config('googledrive.mime_types.folder')) {
                    $files = [...$files, ...$this->getFilesInFolder($item['id'])];
                } else {
                    $files[] = $item;
                }
            }
        }

        return $files;
    }

    public function getFilesInFolder(?string $folderId = 'root', $sharedWithMe = false): iterable
    {
        $files = [];

        if ($folderId == 'MyDrive') {
            $folderId = 'root';
        }

        $folderMimeType = config('googledrive.mime_types.folder');
        $baseQuery = sprintf("((%s) or mimeType='{$folderMimeType}') and %s",
            implode(' or ', $this->getAssetsUsingExtensions()), "'{$folderId}' in parents");
        $endQuery = 'and trashed = false';

        $q = $sharedWithMe
            ? "({$baseQuery}) and sharedWithMe {$endQuery}"
            : "({$baseQuery}) {$endQuery}";

        $folderItems = $this->listFiles(compact('q'));

        foreach ($folderItems as $item) {
            if ($item['mimeType'] === $folderMimeType) {
                $files = [...$files, ...$this->getFilesInFolder($item['id'], $sharedWithMe)];
            } else {
                $files[] = $item;
            }
        }

        return $files;
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        $this->initialize();

        $tempFilePath = tempnam(sys_get_temp_dir(), config('googledrive.name') . '_');

        throw_unless($tempFilePath, CouldNotDownloadFile::class, 'Temporary file not found!');

        $fp = fopen($tempFilePath, 'wb');

        try {
            $storagePath = Path::join(config('manager.directory.originals'), $file->id);
            $fileName = sprintf(
                '%s.%s',
                strtolower($file->id),
                $file->extension
            );

            // Download in 5 MB chunks:
            $chunkSizeBytes = config('manager.chunk_size', 5) * 1048576;
            $chunkStart = 0;

            // Authorize the client:
            $http = $this->client->authorize();
            $firstIteration = true;

            while (true) {
                $chunkEnd = $chunkStart + $chunkSizeBytes;

                try {
                    $response = $http->request(
                        'GET',
                        "/drive/v3/files/{$file->remote_service_file_id}",
                        [
                            'query'   => ['alt' => 'media'], // parameter is required to download from GDrive
                            'headers' => [
                                'Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                            ],
                            'Connection'    => 'keep-alive',
                            'cache-control' => 'no-cache',
                        ]
                    );
                    $this->httpStatus = $response->getStatusCode();

                    if (in_array($this->httpStatus, [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED])) {
                        $this->cleanupTemporaryFile($tempFilePath, $fp);

                        return false;
                    }

                    if ($response->getStatusCode() === Response::HTTP_PARTIAL_CONTENT) {
                        $chunkStart = $chunkEnd + 1;
                        fwrite($fp, $response->getBody()->getContents());
                    } else {
                        break;
                    }
                } catch (GuzzleException|Exception $e) {
                    $this->log($e->getMessage(), 'error');
                    $this->httpStatus = $e->getCode();
                    // The access token may have been invalidated. Retry.
                    $this->client->refreshToken($file->service->refresh_token);
                    $this->client->authorize();

                    if (! $firstIteration && $this->service) {
                        $this->getTokens([
                            'access_token'  => $this->service->access_token,
                            'refresh_token' => $this->service->refresh_token,
                            'expires_in'    => $this->service->expires,
                        ]);
                    }

                    return false;
                }

                $firstIteration = false;
            }

            $path = $this->storage->putFileAs(
                $storagePath,
                new FileUpload($tempFilePath),
                $fileName
            );

            $file->update(['size' => $this->getFileSize($path)]);

            throw_if($path === false, CouldNotDownloadFile::class, 'Failed to initialize file storage');

            return $path;
        } catch (CouldNotDownloadFile|Exception $e) {
            $this->log("Failed to download file from Googledrive: {$e->getMessage()}", 'error', null, $e->getTrace());
        } finally {
            $this->cleanupTemporaryFile($tempFilePath, $fp);
        }

        return false;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        try {
            $key = $this->prepareFileName($file);
            $uploadId = $this->createMultipartUpload($key, $file->mime_type);

            throw_unless($uploadId, CouldNotDownloadFile::class, 'Failed to initialize multi-part upload');

            $chunkSizeBytes = config('manager.chunk_size', 5) * 1024 * 1024;
            $chunkStart = 0;
            $partNumber = 1;
            $parts = [];

            // Authorize the client:
            $http = $this->client->authorize();
            $firstIteration = true;

            while (true) {
                $chunkEnd = $chunkStart + $chunkSizeBytes;

                try {
                    $response = $http->request(
                        'GET',
                        "/drive/v3/files/{$file->remote_service_file_id}",
                        [
                            'query'   => ['alt' => 'media'], // parameter is required to download from GDrive
                            'headers' => [
                                'Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                            ],
                            'Connection'    => 'keep-alive',
                            'cache-control' => 'no-cache',
                        ]
                    );

                    throw_if($response->getStatusCode() == Response::HTTP_NOT_FOUND, CouldNotDownloadFile::class, 'File not found on the server');
                    $this->httpStatus = $response->getStatusCode();

                    if (in_array($this->httpStatus, [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED])) {
                        return false;
                    }

                    if ($response->getStatusCode() == Response::HTTP_PARTIAL_CONTENT) {
                        $parts[] =
                            $this->uploadPart($key, $uploadId, $partNumber++, $response->getBody()->getContents());
                        $chunkStart = $chunkEnd + 1;
                    } else {
                        break;
                    }
                } catch (CouldNotDownloadFile|Throwable|Exception $e) {
                    $this->log($e->getMessage());
                    $this->httpStatus = $e->getCode();

                    // The access token may have been invalidated. Retry.
                    $this->client->refreshToken($file->service->refresh_token);
                    $this->client->authorize();

                    if (! $firstIteration) {
                        // Refresh token may have been revoked or expired. Reauthorise.
                        flash('Your access has expired, you will now be redirected.');

                        $authUrl = $this->client->createAuthUrl();
                        $this->redirectTo($authUrl);
                    }

                    return false;
                }

                $firstIteration = false;
            }

            $completeStatus = $this->completeMultipartUpload($key, $uploadId, $parts);

            throw_unless($completeStatus, CouldNotDownloadFile::class, 'Failed to complete multi-part upload');

            return $completeStatus;
        } catch (CouldNotDownloadFile|Throwable|Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return false;
    }

    public function downloadFromService(File $file): StreamedResponse|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        try {
            // Authorize the client:
            $http = $this->client->authorize();
            $chunkSizeBytes = config('manager.chunk_size', 5) * 1024 * 1024;
            $chunkStart = 0;

            // Create a streamed response
            $response = response()->streamDownload(function () use (&$chunkStart, $chunkSizeBytes, $http, $file) {
                while (true) {
                    $chunkEnd = $chunkStart + $chunkSizeBytes;
                    $response = $http->request(
                        'GET',
                        "/drive/v3/files/{$file->remote_service_file_id}",
                        [
                            'query'         => ['alt' => 'media'], // parameter is required to download from GDrive
                            'headers'       => ['Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd)],
                            'Connection'    => 'keep-alive',
                            'cache-control' => 'no-cache',
                        ]
                    );

                    throw_if($response->getStatusCode() == 404, CouldNotDownloadFile::class, 'File not found on the server');

                    if ($response->getStatusCode() == 206) {
                        echo $response->getBody()->getContents();
                        $chunkStart = $chunkEnd + 1;
                    } elseif ($response->getStatusCode() == 401 || $response->getStatusCode() == 400) {
                        // The access token may have been invalidated. Retry.
                        $this->client->refreshToken($file->service->refresh_token);
                        $http = $this->client->authorize();
                    } else {
                        break;
                    }
                }
            }, $file->name);

            // Set headers for file download
            $response->headers->set('Content-Type', $file->mime_type);
            $response->headers->set('Content-Disposition',
                'attachment; filename="' . $file->name . '.' . $file->extension . '"');
            $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
            $response->headers->set('Pragma', 'public');

            return $response;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return false;
    }

    public function getUser(): ?UserDTO
    {
        try {
            $user = $this->drive->about->get(['fields' => 'user'])->getUser();

            return new UserDTO([
                'email' => $user['emailAddress'],
                'photo' => $user['photoLink'] ?? null,
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return new UserDTO;
        }
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $thumbnailPath = data_get($file, 'thumbnailLink');
        $extension = $this->getFileExtensionFromFileName(data_get($file, 'name'));

        return new FileDTO([
            'user_id'                => $attr['user_id'],
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => pathinfo($file['id'], PATHINFO_FILENAME),
            'md5'                    => data_get($file, 'md5Checksum'),
            'name'                   => pathinfo(data_get($file, 'name'), PATHINFO_FILENAME),
            'thumbnail'              => $thumbnailPath,
            'mime_type'              => data_get($file, 'mimeType'),
            'type'                   => $this->getFileTypeFromExtension($extension),
            'extension'              => $extension,
            'resolution'             => isset($file['videoMediaMetadata']['width'], $file['videoMediaMetadata']['height'])
                ? "{$file['videoMediaMetadata']['width']}x{$file['videoMediaMetadata']['height']}"
                : null,
            'size'         => data_get($file, 'size'),
            'duration'     => data_get($file, 'videoMediaMetadata.durationMillis'),
            'slug'         => str()->slug(pathinfo(data_get($file, 'name'), PATHINFO_FILENAME)),
            'created_time' => data_get($file, 'createdTime')
                ? Carbon::parse($file['createdTime'])->format('Y-m-d H:i:s')
                : null,
            'modified_time' => isset($file['modifiedTime'])
                ? Carbon::parse($file['modifiedTime'])->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public function getMetadataAttributes(?array $properties = null): array
    {
        $metaTypes = ['image', 'video', 'share'];
        $metaProps = data_get($properties, 'metadata') ?? $properties;

        return collect($metaTypes)
            ->map(fn (string $metaType) => data_get($metaProps, $metaType))
            ->filter(fn (?array $value) => filled($value))
            ->values()
            ->toArray();
    }

    public function uploadThumbnail(mixed $file): ?string
    {
        $thumbnailRemoteURl = data_get($file, 'thumbnail');

        if (! $thumbnailRemoteURl) {
            return null;
        }
        // NOTE: it takes time for GD to generate thumbnails so sometimes thumbnail is not available.
        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            $file['id'],
            str()->slug($file['id']) . '.jpg'
        );

        $thumbnailContent = Http::timeout(config('queue.timeout'))->get($thumbnailRemoteURl);

        if ($thumbnailContent->successful()) {
            $this->storage->put($thumbnailPath, $thumbnailContent->body());
        }

        return $thumbnailPath;
    }

    public function initialize()
    {
        $settings = $this->getSettings();
        $authConfig = $settings['authConfig'];
        $redirectUri = $settings['redirectUri'];

        $this->client = new Client;
        $this->client->setAuthConfig($authConfig);
        $this->client->setRedirectUri($redirectUri);
        $this->drive = new Drive($this->client);
        $this->client->setScopes([Drive::DRIVE_READONLY]);
        $this->client->setAccessType(config('googledrive.access_type'));
        $this->client->setApprovalPrompt(config('googledrive.approval_prompt'));
        $this->client->setPrompt(config('googledrive.prompt'));

        if ($this->service) {
            $this->getTokens([
                'access_token'  => $this->service->access_token ?? null,
                'refresh_token' => $this->service->refresh_token ?? null,
                'expires_in'    => $this->service->expires ?? null,
            ]);
        }
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Google Drive settings are required');
        abort_if(count(config('googledrive.settings')) !== $settings->count(), 406, 'All Settings must be present');

        // Updated pattern to match client IDs with 10 to 15 digits at the start
        $clientIdPattern = '/\d{10,15}-[\w-]+\.apps\.googleusercontent\.com$/';
        $clientSecretPattern = '/^[a-zA-Z0-9-_]{24,}$/';

        $clientId = $settings->firstWhere('name', 'GOOGLE_CLIENT_ID')?->payload ?? '';
        $clientSecret = $settings->firstWhere('name', 'GOOGLE_CLIENT_SECRET')?->payload ?? '';

        abort_unless(preg_match($clientIdPattern, $clientId), 406, 'Looks like your client ID format is invalid');
        abort_unless(preg_match($clientSecretPattern, $clientSecret), 406, 'Looks like your client secret format is invalid');

        try {
            $this->client->setClientId($clientId);
            $this->client->setClientSecret($clientSecret);
            $authUrl = $this->client->createAuthUrl();
            $authUrlPattern = '/^https:\/\/accounts\.google\.com\/o\/oauth2\/(auth|v2\/auth)\?/';

            abort_unless(preg_match($authUrlPattern, $authUrl), 406, 'Looks like your auth URL format is invalid');

            return true;
        } catch (Exception $e) {
            abort(500, $e->getMessage());
        }
    }

    public function getSettings($customKeys = null): array
    {
        $settings = parent::getSettings($customKeys);

        $authConfig =
            isset($settings['GOOGLE_PROJECT_ID'], $settings['GOOGLE_CLIENT_ID'], $settings['GOOGLE_CLIENT_SECRET']) ? [
                'project_id'    => $settings['GOOGLE_PROJECT_ID'],
                'client_id'     => $settings['GOOGLE_CLIENT_ID'],
                'client_secret' => $settings['GOOGLE_CLIENT_SECRET'],
            ] : [
                'project_id'    => config('googledrive.project_id'),
                'client_id'     => config('googledrive.client_id'),
                'client_secret' => config('googledrive.client_secret'),
            ];

        $redirectUri = config('googledrive.redirect_uri');

        return compact('authConfig', 'redirectUri');
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        if (data_get($tokens, 'access_token')) {
            $this->client->setAccessToken($tokens);
            $this->handleTokenExpiration();

            return new TokenDTO($tokens);
        }

        $code = request('code');

        if (empty($code)) {
            $this->log('Code not found in request', 'error');

            throw new CouldNotGetToken('Code not found in request');
        }

        try {
            $tokens = $this->client->fetchAccessTokenWithAuthCode($code);

            if (isset($tokens['error'])) {
                $errorMessage = $tokens['error_description'] ?? 'Unknown error during token retrieval';
                $this->log("Error during token retrieval: {$errorMessage}", 'error');
                $this->redirectTo(config('googledrive.redirect_uri'));
            }

            $this->client->setAccessToken($tokens);
            $this->handleTokenExpiration();

            $this->service?->update([
                'access_token'  => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
            ]);

            return new TokenDTO($this->client->getAccessToken());
        } catch (Exception $e) {
            $this->log("Error during token retrieval: {$e->getMessage()}", 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    public function handleTokenExpiration()
    {
        if ($this->client->isAccessTokenExpired()) {
            if ($this->client->getRefreshToken()) {
                $cred = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());

                if (! empty($cred['access_token'])) {
                    $this->service->update([
                        'access_token' => $cred['access_token'],
                        'expires'      => $cred['expires_in'],
                    ]);
                    $this->client->setAccessToken($cred);
                }

                if (! empty($cred['error'])) {
                    $this->service->status = IntegrationStatus::UNAUTHORIZED;
                    $this->service->save();
                }
            } else {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }
        }
    }

    private function getAssetsUsingExtensions(): array
    {
        return Arr::map(
            Arr::wrap(config('manager.meta.file_extensions')),
            fn ($ext) => "name contains '.{$ext}'"
        );
    }

    private function listFilesRecursive($params, $nextPageToken = null, $reTry = 0): Generator
    {
        try {
            $response = $this->drive->files->listFiles([
                ...[...config('googledrive.default_params'), ...$params],
                'pageToken' => $nextPageToken,
            ]);
            yield from data_get($response, 'files');

            if ($nextPageToken = data_get($response, 'nextPageToken')) {
                yield from $this->listFilesRecursive($params, $nextPageToken, $reTry);
            }
        } catch (Exception $e) {
            $this->httpStatus = $e->getCode();

            if ($reTry < 2) {
                $reTry++;
                $this->handleTokenExpiration();
                yield from $this->listFilesRecursive($params, $nextPageToken, $reTry);
            }
        }
    }
}
