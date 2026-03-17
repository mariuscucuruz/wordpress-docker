<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs;

use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class RunStats extends BaseDTO
{
    public int $inputBodyLen;

    public int $restartCount;

    public int $resurrectCount;

    public float $memAvgBytes;

    public int $memMaxBytes;

    public int $memCurrentBytes;

    public float $cpuAvgUsage;

    public float $cpuMaxUsage;

    public float $cpuCurrentUsage;

    public int $netRxBytes;

    public int $netTxBytes;

    public int $durationMillis;

    public float $runTimeSecs;

    public int $metamorph;

    public float $computeUnits;
}
