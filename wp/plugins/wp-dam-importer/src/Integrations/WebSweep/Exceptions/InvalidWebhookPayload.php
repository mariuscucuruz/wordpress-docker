<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Exceptions;

use Exception;

class InvalidWebhookPayload extends Exception
{
    const string DEFAULT_ERROR_MESSAGE = 'Invalid WebSweep webhook payload: one or more fields are missing.';

    public static function make(?string $message = self::DEFAULT_ERROR_MESSAGE): self
    {
        return new self($message);
    }
}
