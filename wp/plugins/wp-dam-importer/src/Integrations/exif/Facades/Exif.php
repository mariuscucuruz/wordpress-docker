<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Exif\Facades;

use Illuminate\Support\Facades\Facade;

class Exif extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'exif';
    }
}
