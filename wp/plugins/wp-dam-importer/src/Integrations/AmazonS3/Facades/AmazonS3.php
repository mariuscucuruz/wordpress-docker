<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AmazonS3\Facades;

use Illuminate\Support\Facades\Facade;

class AmazonS3 extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'amazons3';
    }
}
