<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\GoogleAds\Enum;

enum GoogleAdObjectType: string
{
    case MANAGER = 'MANAGER';
    case CUSTOMER = 'CUSTOMER';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}
