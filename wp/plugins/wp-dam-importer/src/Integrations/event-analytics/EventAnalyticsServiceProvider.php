<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\EventAnalytics;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class EventAnalyticsServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'eventanalytics';

    protected string $classname = EventAnalytics::class;

    protected string $path = __DIR__ . '/..';
}
