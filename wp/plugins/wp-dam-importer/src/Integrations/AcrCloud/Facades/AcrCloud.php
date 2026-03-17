<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Facades;

use Illuminate\Support\Facades\Facade;

class AcrCloud extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'acrcloud';
    }
}
