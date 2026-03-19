<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Exceptions;

use Spatie\WebhookClient\Exceptions\InvalidConfig;

class InvalidAcrCloudWebhookPayload extends InvalidConfig
{
    public static function make(): self
    {
        return new static('Invalid ACR Cloud webhook payload: one or more fields are missing.');
    }
}
