<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Exceptions;

use Exception;

class OAuthTokenRetrievalFailed extends Exception
{
    public static function make(?string $type = 'error'): self
    {
        return new self('Failed to retrieve an oauth token', $type);
    }
}
