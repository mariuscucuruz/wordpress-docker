<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Interfaces;

use MariusCucuruz\DAMImporter\Models\File;

interface CanUpload
{
    public function uploadData(File $file, mixed $data, ?string $prefix = null): bool;
}
