<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Tiktok;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class TiktokServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'tiktok';

    protected string $classname = Tiktok::class;

    protected string $path = __DIR__ . '/..';
}
