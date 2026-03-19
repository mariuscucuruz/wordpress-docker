<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Vimeo;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class VimeoServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'vimeo';

    protected string $classname = Vimeo::class;

    protected string $path = __DIR__ . '/..';
}
