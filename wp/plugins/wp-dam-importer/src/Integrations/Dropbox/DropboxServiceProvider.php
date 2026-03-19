<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Dropbox;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class DropboxServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'dropbox';

    protected string $classname = Dropbox::class;

    protected string $path = __DIR__ . '/..';
}
