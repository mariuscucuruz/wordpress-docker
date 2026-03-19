<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Egnyte\Facades;

use Illuminate\Support\Facades\Facade;

class Egnyte extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'egnyte';
    }
}
