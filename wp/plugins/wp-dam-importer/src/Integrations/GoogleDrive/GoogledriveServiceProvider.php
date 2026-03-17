<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Googledrive;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class GoogledriveServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'googledrive';

    protected string $classname = Googledrive::class;

    protected string $path = __DIR__ . '/..';
}
