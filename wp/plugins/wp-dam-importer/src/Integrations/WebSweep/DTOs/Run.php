<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs;

use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class Run extends BaseDTO
{
    public ?RunData $data = null;

    public static function make(array $runData = []): self
    {
        $run = new self;
        $run->data = RunData::fromArray($runData);

        return $run;
    }
}
