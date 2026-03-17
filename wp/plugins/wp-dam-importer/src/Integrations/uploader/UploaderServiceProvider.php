<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Uploader;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class UploaderServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'uploader';

    protected string $classname = Uploader::class;

    protected string $path = __DIR__ . '/..';
}
