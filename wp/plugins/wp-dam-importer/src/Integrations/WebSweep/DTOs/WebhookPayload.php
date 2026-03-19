<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs;

use Carbon\Carbon;
use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class WebhookPayload extends BaseDTO
{
    public string $userId;

    public Carbon $createdAt;

    public string $eventType;

    public ?WebhookPayloadEventData $eventData = null;

    public ?WebhookPayloadResource $resource = null;
}
