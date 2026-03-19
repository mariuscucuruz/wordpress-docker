<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks\Contracts;

use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;

interface NataeroOperationStrategy
{
    public function fileOperation(): NataeroFunctionType;

    public function payloadKey(): string;
}
