<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs;

use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class RunOptions extends BaseDTO
{
    public string $build;

    public int $timeoutSecs;

    public int $memoryMbytes;

    public int $diskMbytes;
}
