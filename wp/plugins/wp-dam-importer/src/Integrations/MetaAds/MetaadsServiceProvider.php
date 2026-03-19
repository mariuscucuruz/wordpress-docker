<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Metaads;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class MetaadsServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'metaads';

    protected string $classname = Metaads::class;

    protected string $path = __DIR__ . '/..';
}
