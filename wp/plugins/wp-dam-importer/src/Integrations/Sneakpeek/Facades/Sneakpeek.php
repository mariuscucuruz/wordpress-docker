<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sneakpeek\Facades;

use Illuminate\Support\Facades\Facade;

class Sneakpeek extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'sneakpeek';
    }
}
