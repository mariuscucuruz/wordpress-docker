<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediainfo;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class MediainfoServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'mediainfo';

    protected string $classname = Mediainfo::class;

    protected string $path = __DIR__ . '/..';

    protected array $registerCommands = [
        Commands\EnqueueMediaInfoCommand::class,
        Commands\MediaInfoForgetCommand::class,
    ];
}
