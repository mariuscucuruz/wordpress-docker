<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Brandfolder;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class BrandfolderServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'brandfolder';

    protected string $classname = Brandfolder::class;

    protected string $path = __DIR__;
}
