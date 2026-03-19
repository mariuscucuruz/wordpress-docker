<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Pinterest\Facades;

use Illuminate\Support\Facades\Facade;

class Pinterest extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return strtolower(class_basename(static::class));
    }
}
