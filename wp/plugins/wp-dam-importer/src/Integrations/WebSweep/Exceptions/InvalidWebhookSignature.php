<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Exceptions;

use Exception;

class InvalidWebhookSignature extends Exception
{
    public static function make(): self
    {
        return new self('Invalid webhook signature.');
    }
}
