<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs;

use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class ActorRun extends BaseDTO
{
    public string $id;

    public string $actId;

    public string $userId;

    public string $startedAt;

    public ?string $finishedAt;

    public string $status;

    public string $buildId;

    public string $defaultKeyValueStoreId;

    public string $defaultDatasetId;

    public string $defaultRequestQueueId;

    public string $containerUrl;
}
