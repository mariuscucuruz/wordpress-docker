<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Aprimo;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class AprimoServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'aprimo';

    protected string $classname = Aprimo::class;

    protected string $path = __DIR__;
}
