<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Pagination;

use MariusCucuruz\DAMImporter\Models\File;
use RuntimeException;

trait Paginates
{
    /**
     * Get the default pagination type for this package.
     * Override to change the pagination strategy.
     */
    protected function getPaginationType(): PaginationType
    {
        return PaginationType::Token;
    }

    /**
     * Fetch a page of items from the API.
     * Override this method or override paginate() completely.
     */
    protected function fetchPage(?string $folderId, mixed $cursor, array $folderMeta = []): PaginatedResponse
    {
        throw new RuntimeException('fetchPage() must be implemented or paginate() must be overridden');
    }


    /**
     * Main pagination entry point - implements CanPaginate interface.
     */
    public function paginate(array $request = []): void
    {
        $folders = $this->normalizeRequest($request);

        if (empty($folders)) {
            foreach ($this->getRootFolders() as $rootFolder) {
                $this->paginateFolder(data_get($rootFolder, 'id'), $rootFolder);
            }

            return;
        }

        $folders = $this->filterDisabledFolders($folders);

        foreach ($folders as $folder) {
            $folderId = data_get($folder, 'folder_id');
            $startTime = data_get($folder, 'start_time');
            $endTime = data_get($folder, 'end_time');

            if (! empty($startTime) && ! empty($endTime)) {
                $this->log("Invalid date range for folder: {$folderId}", 'error');

                continue;
            }

            $this->paginateFolder($folderId, $folder);
        }
    }

    /**
     * Normalize request to unified format supporting both folder_ids and metadata.
     */
    protected function normalizeRequest(array $request): array
    {
        // Format 1: folder_ids (simple array)
        if (! empty($request['folder_ids'])) {
            return array_map(fn ($id) => ['folder_id' => $id, 'start_time' => null, 'end_time' => null], $request['folder_ids']);
        }

        // Format 2: metadata (array with folder_id, start_time, end_time)
        return $request['metadata'] ?? [];
    }

    /**
     * Paginate through a single folder, handling subfolders recursively.
     */
    protected function paginateFolder(?string $folderId, array $folderMeta = []): void
    {
        $cursor = $this->getInitialCursor();
        $pageCount = 0;

        do {
            $response = $this->fetchPage($folderId, $cursor, $folderMeta);

            // Handle subfolders recursively
            foreach ($response->subfolders as $subfolder) {
                $this->paginateFolder(
                    data_get($subfolder, 'id'),
                    $subfolder
                );
            }

            // Apply date filter if enabled
            $items = $this->applyDateFilter($response->items);

            // Transform and filter supported extensions
            $items = $this->transformItems($items);
            $items = $this->filterSupportedExtensions($items);

            if (filled($items)) {
                $importGroupName = $this->getImportGroupName($folderId, $folderMeta, $pageCount);
                $this->dispatch($items, $importGroupName);
            }

            $cursor = $this->advanceCursor($cursor, $response);
            $pageCount++;
        } while ($cursor !== null);
    }

    /**
     * Get the initial cursor value based on pagination type.
     */
    protected function getInitialCursor(): mixed
    {
        return match ($this->getPaginationType()) {
            PaginationType::Page => $this->getPageStart(),
            default              => null,
        };
    }

    /**
     * Get the starting page number for page-based pagination.
     * Override if the API uses a different starting page (e.g., 0 instead of 1).
     */
    protected function getPageStart(): int
    {
        return 1;
    }

    /**
     * Advance the cursor based on pagination type and response.
     */
    protected function advanceCursor(mixed $currentCursor, PaginatedResponse $response): mixed
    {
        if ($response->nextCursor === null) {
            return null;
        }

        return match ($this->getPaginationType()) {
            PaginationType::Page => (int) $currentCursor + 1,
            default              => $response->nextCursor,
        };
    }

    /**
     * Apply date filter to items if date sync filter is enabled.
     */
    protected function applyDateFilter(array $items): array
    {
        if ($this->isDateSyncFilter) {
            return array_filter($items, fn ($item) => $this->isWithinDatePeriod($this->getItemDate($item)));
        }

        return $items;
    }

    /**
     * Get the date field from an item for date filtering.
     * Override in packages if the date field differs.
     */
    protected function getItemDate(array $item): mixed
    {
        return data_get($item, 'LastModified')
            ?? data_get($item, 'modified_time')
            ?? data_get($item, 'modifiedTime')
            ?? data_get($item, 'createdTime')
            ?? data_get($item, 'created_time');
    }

    /**
     * Filter items to only supported file extensions.
     * Uses filterSupportedFileExtensions from SourcePackageManager if available.
     */
    protected function filterSupportedExtensions(array $items): array
    {
        if (method_exists($this, 'filterSupportedFileExtensions')) {
            return $this->filterSupportedFileExtensions($items);
        }

        return $items;
    }

    /**
     * Generate import group name for dispatch.
     * Override if package needs custom naming logic.
     */
    protected function getImportGroupName(?string $folderId, array $folderMeta, int $pageCount): string
    {
        $baseName = $folderId ?? 'root';

        // Append page count if multiple pages to avoid collisions
        if ($pageCount > 0) {
            return "{$baseName}_{$pageCount}";
        }

        return $baseName;
    }

    /**
     * Dispatch files for processing - implements CanPaginate interface.
     *
     * @throws \JsonException
     */
    public function dispatch(?array $files, string|int $importGroupName): void
    {
        File::massCreate($this->service, $files ?: [], $importGroupName);
    }
}
