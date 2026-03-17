<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Dropbox\Traits;

use Exception;
use Kunnu\Dropbox\Models\File;
use Kunnu\Dropbox\Models\FileMetadata;

trait ListsFilesAndFolders
{
    public function listFolderContent(?array $request): iterable
    {
        $dropbox = $this->initialize($this->getAccessToken());
        $folderId = $request['folder_id'] ?? 'root';
        $path = $folderId === 'root' ? '' : DIRECTORY_SEPARATOR . ltrim($folderId, DIRECTORY_SEPARATOR);
        $newItems = [];

        try {
            $response = $dropbox->listFolder($path);
            $files = $response->getItems();

            foreach ($files as $file) {
                $isDir = $file->getDataProperty('.tag') === 'folder';
                $fileData = $file->getData();

                if ($isDir) {
                    $fileData['id'] = $fileData['path_display'];
                }

                $newItems[] = [
                    'isDir'        => $isDir,
                    'thumbnailUrl' => $file->getPathDisplay(),
                    'name'         => $file->getName(),
                    'path'         => $file->getPathDisplay(),
                    'metadata'     => $fileData,
                    ...$fileData,
                ];
            }
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return $newItems;
    }

    public function listFolderSubFolders(?array $request): array
    {
        $result = collect();

        /** @var null|array|int|string[] $folderIds */
        $folderIds = data_get($request, 'folder_ids', []);
        $folderIds = array_filter($folderIds);

        /** @var string[] $fileIds */
        $fileIds = data_get($request, 'file_ids', []);
        $fileIds = array_values(array_filter($fileIds));

        if (! empty($folderIds)) {
            array_walk(
                $folderIds,
                fn ($path) => is_string($path) && $result->push(...$this->getFilesInFolder($path))
            );
        }

        if (! empty($fileIds)) {
            $dropbox = $this->initialize($this->getAccessToken());

            foreach ($fileIds as $path) {
                try {
                    /** @var File|FileMetadata $file */
                    $file = $dropbox->getMetadata($path);

                    $result->push([
                        'isDir'        => $file->getDataProperty('.tag') === 'folder',
                        'thumbnailUrl' => $file->getPathDisplay(),
                        'name'         => $file->getName(),
                        'path'         => $file->getPathLower(),
                        'metadata'     => $file->getData(),
                        ...$file->getData(),
                    ]);
                } catch (Exception $e) {
                    $this->httpStatus = $e->getCode();
                    $this->log("No metadata for file {$path}: {$e->getMessage()}", 'error');
                }
            }
        }

        return $result->toArray();
    }

    public function getFilesInFolder(?string $folderId = 'root'): iterable
    {
        $dropbox = $this->initialize($this->getAccessToken());
        $result = collect();

        try {
            $response = $dropbox->listFolder($folderId);

            /** @var File[]|FileMetadata[] $files */
            $files = $response->getItems();

            foreach ($files as $file) {
                $type = $file->getDataProperty('.tag');

                if ($type === 'folder') {
                    // Recursively get items in subfolders
                    $folderFiles = $this->getFilesInFolder($file->getPathLower());
                    $result->push(...$folderFiles);
                }

                if ($type !== 'folder') {
                    $result->push([
                        'isDir'        => false,
                        'thumbnailUrl' => $file->getPathDisplay(),
                        'name'         => $file->getName(),
                        'path'         => $file->getPathLower(),
                        'metadata'     => $file->getData(),
                        ...$file->getData(),
                    ]);
                }
            }
        } catch (Exception $e) {
            $this->httpStatus = $e->getCode();
            $this->log($e->getMessage(), 'error');
        }

        return $result->toArray();
    }
}
