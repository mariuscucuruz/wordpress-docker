<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Tiktok\Facades;

use Illuminate\Support\Facades\Facade;

class Tiktok extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'tiktok';
    }
}
