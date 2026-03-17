<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sneakpeek;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class SneakpeekServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'sneakpeek';

    protected string $classname = Sneakpeek::class;

    protected string $path = __DIR__ . '/..';

    protected array $registerCommands = [
        Commands\MediaSneakpeek::class,
        Commands\SneakpeekForgetCommand::class,
    ];
}
