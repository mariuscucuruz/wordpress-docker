<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Aprimo\Facades;

use Illuminate\Support\Facades\Facade;

class Aprimo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return strtolower(class_basename(static::class));
    }
}
