<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sftp;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class SftpServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'sftp';

    protected string $classname = Sftp::class;

    protected string $path = __DIR__ . '/..';
}
