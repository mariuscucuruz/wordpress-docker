<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Facades;

use Illuminate\Support\Facades\Facade;

class Mediaconvert extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'mediaconvert';
    }
}
