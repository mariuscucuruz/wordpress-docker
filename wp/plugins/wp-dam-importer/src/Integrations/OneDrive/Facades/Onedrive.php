<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Onedrive\Facades;

use Illuminate\Support\Facades\Facade;

class Onedrive extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'onedrive';
    }
}
