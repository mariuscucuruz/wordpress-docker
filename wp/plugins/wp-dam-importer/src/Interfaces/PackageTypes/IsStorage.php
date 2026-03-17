<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Interfaces\PackageTypes;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\StorageDTO;

/**
 * future implementation / TBD
 */
interface IsStorage
{
    public function storageProperties(array $storage): StorageDTO;

    public function get(string $path): ?string;

    public function put(File $file, ?string $directory = null): string|bool;

    public function files(?string $directory = null, bool $recursive = false): FileDTO;

    public function allFiles(?string $directory = null): FileDTO;
}
