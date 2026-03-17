<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class FrameioServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'frameio';

    protected string $classname = Frameio::class;

    protected string $path = __DIR__;
}
