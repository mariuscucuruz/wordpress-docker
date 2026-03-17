<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Acquia\Facades;

use Illuminate\Support\Facades\Facade;

class Acquia extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'acquia';
    }
}
