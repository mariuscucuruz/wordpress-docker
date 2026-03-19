<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs;

use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class WebhookPayloadEventData extends BaseDTO
{
    public string $actorId;

    public string $actorRunId;
}
