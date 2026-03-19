<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Exceptions;

use Exception;

class InvalidSettingValue extends Exception
{
    public static function make(string $name, ?string $type = 'error'): self
    {
        return new self("Invalid setting value for {$name}.", $type);
    }
}
