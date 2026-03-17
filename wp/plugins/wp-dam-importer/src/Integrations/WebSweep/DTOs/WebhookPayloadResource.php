<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs;

use Carbon\Carbon;
use MariusCucuruz\DAMImporter\DTOs\BaseDTO;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Enums\WebSweepRunStatus;

class WebhookPayloadResource extends BaseDTO
{
    public ?string $id = null;

    public ?string $actId = null;

    public ?string $userId = null;

    public ?Carbon $startedAt = null;

    public ?Carbon $finishedAt = null;

    public ?WebSweepRunStatus $status = null;

    public ?array $stats = [];

    public ?array $meta = [];
}
