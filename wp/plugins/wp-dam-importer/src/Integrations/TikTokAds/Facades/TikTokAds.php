<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds\Facades;

use Illuminate\Support\Facades\Facade;

class TikTokAds extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return class_basename(static::class);
    }
}
