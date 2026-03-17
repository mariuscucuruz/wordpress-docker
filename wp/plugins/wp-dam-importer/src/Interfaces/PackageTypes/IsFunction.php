<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Interfaces\PackageTypes;

use MariusCucuruz\DAMImporter\Models\File;

interface IsFunction
{
    public function process(File $file): bool;
}
