<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Pinterest;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class PinterestServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'pinterest';

    protected string $classname = Pinterest::class;

    protected string $path = __DIR__;

    protected ?string $tagname = 'integration';
}
