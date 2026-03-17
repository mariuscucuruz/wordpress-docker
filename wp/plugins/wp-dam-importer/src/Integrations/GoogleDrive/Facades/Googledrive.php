<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Googledrive\Facades;

use Illuminate\Support\Facades\Facade;

class Googledrive extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'googledrive';
    }
}
