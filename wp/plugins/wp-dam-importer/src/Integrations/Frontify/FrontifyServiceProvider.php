<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frontify;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class FrontifyServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'frontify';

    protected string $classname = Frontify::class;

    protected string $path = __DIR__;
}
