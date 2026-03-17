<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Interfaces;

use MariusCucuruz\DAMImporter\Models\File;

interface HasVersions
{
    public function saveVersion(File $file, array $attributes = []): bool;
}
