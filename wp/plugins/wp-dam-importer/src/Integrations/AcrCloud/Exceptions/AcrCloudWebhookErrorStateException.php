<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Exceptions;

use Exception;

class AcrCloudWebhookErrorStateException extends Exception
{
    public static function make(): self
    {
        return new self('ACRCloud webhook returned an error state.');
    }
}
