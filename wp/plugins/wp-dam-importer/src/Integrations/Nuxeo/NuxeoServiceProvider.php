<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nuxeo;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class NuxeoServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'nuxeo';

    protected string $classname = Nuxeo::class;

    protected string $path = __DIR__ . '/..';
}
