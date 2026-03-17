<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks\Strategies;

use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Webhooks\Contracts\NataeroOperationStrategy;

final class MediaExifStrategy implements NataeroOperationStrategy
{
    public function fileOperation(): NataeroFunctionType
    {
        return NataeroFunctionType::EXIF;
    }

    public function payloadKey(): string
    {
        return 'results.raw';
    }
}
