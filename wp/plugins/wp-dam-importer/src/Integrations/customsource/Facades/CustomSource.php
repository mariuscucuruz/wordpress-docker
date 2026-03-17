<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\CustomSource\Facades;

use Illuminate\Support\Facades\Facade;

class CustomSource extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'customsource';
    }
}
