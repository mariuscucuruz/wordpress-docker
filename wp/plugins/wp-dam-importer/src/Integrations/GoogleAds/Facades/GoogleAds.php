<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\GoogleAds\Facades;

use Illuminate\Support\Facades\Facade;

class GoogleAds extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return strtolower(class_basename(static::class));
    }
}
