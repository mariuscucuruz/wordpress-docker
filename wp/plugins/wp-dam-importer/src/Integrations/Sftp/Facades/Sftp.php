<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sftp\Facades;

use Illuminate\Support\Facades\Facade;

class Sftp extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'sftp';
    }
}
