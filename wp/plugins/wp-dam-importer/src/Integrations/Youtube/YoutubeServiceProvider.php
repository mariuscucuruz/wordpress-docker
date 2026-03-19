<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Youtube;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class YoutubeServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'youtube';

    protected string $classname = Youtube::class;

    protected string $path = __DIR__ . '/..';
}
