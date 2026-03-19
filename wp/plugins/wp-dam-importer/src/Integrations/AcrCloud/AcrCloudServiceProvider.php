<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;
use Spatie\WebhookClient\WebhookClientServiceProvider;

class AcrCloudServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'acrcloud';

    protected string $classname = AcrCloud::class;

    protected string $path = __DIR__ . '/..';

    protected array $registerCommands = [
        Commands\DispatchAcrCloudCommand::class,
        Commands\RetrieveAcrCloudCommand::class,
        Commands\ForgetAcrCloudCommand::class,
        Commands\ReprocessAcrCloudNoResultsCommand::class,
    ];

    protected array $registerProviders = [
        WebhookClientServiceProvider::class,
    ];
}
