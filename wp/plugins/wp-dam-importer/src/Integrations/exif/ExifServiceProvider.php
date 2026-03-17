<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Exif;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class ExifServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'exif';

    protected string $classname = Exif::class;

    protected string $path = __DIR__ . '/..';

    protected array $registerCommands = [
        Commands\EnqueueMediaExifCommand::class,
        Commands\MediaExifForgetCommand::class,
    ];
}
