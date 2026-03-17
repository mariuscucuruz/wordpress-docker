<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Egnyte;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class EgnyteServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'egnyte';

    protected string $classname = Egnyte::class;

    protected string $path = __DIR__ . '/..';
}
