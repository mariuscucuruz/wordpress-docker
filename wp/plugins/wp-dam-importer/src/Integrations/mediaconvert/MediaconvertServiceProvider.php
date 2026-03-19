<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediaconvert;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class MediaconvertServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'mediaconvert';

    protected string $classname = Mediaconvert::class;

    protected string $path = __DIR__ . '/..';

    protected array $registerCommands = [
        Commands\EnqueueMediaConvertCommand::class,
        Commands\RetrieveMediaConvertCommand::class,
        Commands\MediaConvertForgetCommand::class,
    ];
}
