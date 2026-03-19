<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Interfaces;

interface HasFolders
{
    /**
     * @internal \MariusCucuruz\DAMImporter\Jobs\Sync\SyncFilesAndFoldersJob::index()
     *
     * @note SyncFilesAndFoldersJob requires listFolderSubFolders())
     */
    // public function listFolderSubFolders(?array $request): iterable;

    public function listFolderContent(?array $request): iterable;
}
