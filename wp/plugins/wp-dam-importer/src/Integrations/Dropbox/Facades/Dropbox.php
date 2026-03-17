<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Dropbox\Facades;

use Illuminate\Support\Facades\Facade;

class Dropbox extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'dropbox';
    }
}
