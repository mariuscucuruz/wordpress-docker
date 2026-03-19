<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\S3;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class S3ServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 's3';

    protected string $classname = S3::class;

    protected string $path = __DIR__ . '/..';
}
