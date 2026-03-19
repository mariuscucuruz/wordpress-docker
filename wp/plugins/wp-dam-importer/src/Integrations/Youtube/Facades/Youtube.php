<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Youtube\Facades;

use Illuminate\Support\Facades\Facade;

class Youtube extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'youtube';
    }
}
