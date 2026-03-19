<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AdobeExperienceManager\Facades;

use Illuminate\Support\Facades\Facade;

class AdobeExperienceManager extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'adobeexperiencemanager';
    }
}
