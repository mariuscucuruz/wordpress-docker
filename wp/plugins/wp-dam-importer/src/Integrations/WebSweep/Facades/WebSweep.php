<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Facades;

use Illuminate\Support\Facades\Facade;

class WebSweep extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'WebSweep';
    }
}
