<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Vimeo\Facades;

use Illuminate\Support\Facades\Facade;

class Vimeo extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'vimeo';
    }
}
