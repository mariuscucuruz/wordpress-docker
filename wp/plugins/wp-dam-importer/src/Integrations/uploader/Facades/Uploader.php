<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Uploader\Facades;

use Illuminate\Support\Facades\Facade;

class Uploader extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'uploader';
    }
}
