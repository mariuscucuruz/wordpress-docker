<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Exceptions;

use Spatie\WebhookClient\Exceptions\InvalidWebhookSignature;

class InvalidAcrCloudState extends InvalidWebhookSignature
{
    public static function make(?string $msg = null): self
    {
        return new static($msg);
    }
}
