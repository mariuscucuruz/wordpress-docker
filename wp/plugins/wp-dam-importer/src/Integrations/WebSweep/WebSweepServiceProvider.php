<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class WebSweepServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'websweep';

    protected string $classname = WebSweep::class;

    protected string $path = __DIR__;

    protected ?string $tagname = 'integration';
}
