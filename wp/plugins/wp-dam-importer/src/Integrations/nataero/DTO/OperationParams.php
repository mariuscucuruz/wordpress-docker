<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\DTO;

use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;

class OperationParams
{
    public function __construct(
        public NataeroFunctionType $nataeroFunctionType,
        public ?string $type = null,
    ) {}
}
