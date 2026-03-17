<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Exceptions;

use Exception;
use MariusCucuruz\DAMImporter\Traits\Loggable;

class CouldNotDownloadFile extends Exception
{
    use Loggable;

    public function report(?string $type = 'error'): void
    {
        $this->log($this->getMessage(), $type);
    }
}
