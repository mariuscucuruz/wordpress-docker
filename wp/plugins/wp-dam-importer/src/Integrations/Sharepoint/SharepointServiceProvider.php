<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sharepoint;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class SharepointServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'sharepoint';

    protected string $classname = Sharepoint::class;

    protected string $path = __DIR__ . '/..';
}
