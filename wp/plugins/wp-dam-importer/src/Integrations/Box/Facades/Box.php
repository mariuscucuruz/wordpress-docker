<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Box\Facades;

use Illuminate\Support\Facades\Facade;

class Box extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return class_basename(static::class);
    }
}
