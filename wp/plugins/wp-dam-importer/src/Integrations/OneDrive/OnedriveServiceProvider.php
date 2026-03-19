<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Onedrive;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class OnedriveServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'onedrive';

    protected string $classname = Onedrive::class;

    protected string $path = __DIR__ . '/..';
}
