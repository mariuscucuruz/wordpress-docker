<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sharepoint\Facades;

use Illuminate\Support\Facades\Facade;

class Sharepoint extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'sharepoint';
    }
}
