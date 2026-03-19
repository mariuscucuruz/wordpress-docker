<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Brandfolder\Facades;

use Illuminate\Support\Facades\Facade;

class Brandfolder extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return strtolower(class_basename(static::class));
    }
}
