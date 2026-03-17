<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Bynder;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class BynderServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'bynder';

    protected string $classname = Bynder::class;

    protected string $path = __DIR__ . '/..';
}
