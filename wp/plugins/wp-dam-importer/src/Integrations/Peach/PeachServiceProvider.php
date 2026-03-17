<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Peach;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class PeachServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'peach';

    protected string $classname = Peach::class;

    protected string $path = __DIR__ . '/..';
}
