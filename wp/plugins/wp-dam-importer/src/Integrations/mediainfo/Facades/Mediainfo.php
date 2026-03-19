<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediainfo\Facades;

use Illuminate\Support\Facades\Facade;

class Mediainfo extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'mediainfo';
    }
}
