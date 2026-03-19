<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Instagram\Facades;

use Illuminate\Support\Facades\Facade;

class Instagram extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'instagram';
    }
}
