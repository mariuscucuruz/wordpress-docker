<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Exceptions;

use Exception;
use MariusCucuruz\DAMImporter\Traits\Loggable;

class CouldNotGetToken extends Exception
{
    use Loggable;

    public function report(?string $message = null, ?string $type = 'error'): void
    {
        $this->log($message ?? $this->getMessage(), $type);
    }
}
