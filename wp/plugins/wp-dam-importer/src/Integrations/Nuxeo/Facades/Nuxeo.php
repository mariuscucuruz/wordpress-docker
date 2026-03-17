<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nuxeo\Facades;

use Illuminate\Support\Facades\Facade;

class Nuxeo extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'nuxeo';
    }
}
