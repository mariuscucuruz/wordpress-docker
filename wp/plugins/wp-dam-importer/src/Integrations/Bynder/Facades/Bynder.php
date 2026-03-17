<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Bynder\Facades;

use Illuminate\Support\Facades\Facade;

class Bynder extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'bynder';
    }
}
