<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Box;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class BoxServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'box';

    protected string $classname = Box::class;

    protected string $path = __DIR__;
}
