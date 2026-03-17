<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\LinkedIn\Facades;

use Illuminate\Support\Facades\Facade;

class LinkedIn extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return strtolower(class_basename(static::class));
    }
}
