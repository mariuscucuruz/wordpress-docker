<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Peach\Facades;

use Illuminate\Support\Facades\Facade;

class Peach extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'peach';
    }
}
