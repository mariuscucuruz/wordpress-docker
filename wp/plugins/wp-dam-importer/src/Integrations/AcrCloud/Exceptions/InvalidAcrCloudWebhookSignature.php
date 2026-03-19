<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Exceptions;

use Spatie\WebhookClient\Exceptions\InvalidWebhookSignature;

class InvalidAcrCloudWebhookSignature extends InvalidWebhookSignature
{
    public static function make(): self
    {
        return new static('Invalid ACR Cloud webhook signature.');
    }
}
