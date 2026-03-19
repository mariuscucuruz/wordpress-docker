<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Facebook\Facades;

use Illuminate\Support\Facades\Facade;

class Facebook extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'facebook';
    }
}
