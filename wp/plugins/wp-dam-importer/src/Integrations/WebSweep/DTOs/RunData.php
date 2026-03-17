<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs;

use Carbon\Carbon;
use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class RunData extends BaseDTO
{
    public string $id;

    public string $actId;

    public string $userId;

    public ?string $actorTaskId;

    public Carbon $startedAt;

    public ?Carbon $finishedAt;

    public string $status;

    public ?string $statusMessage;

    public ?bool $isStatusMessageTerminal;

    public RunStats $stats;

    public RunOptions $options;

    public string $buildId;

    public ?int $exitCode;

    public string $defaultKeyValueStoreId;

    public string $defaultDatasetId;

    public string $defaultRequestQueueId;

    public string $buildNumber;

    public string $containerUrl;

    public ?bool $isContainerServerReady;

    public ?string $gitBranchName;

    public ?float $usageTotalUsd;
}
