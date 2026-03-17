<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Instagram\Enums;

enum InstagramServiceType: string
{
    case BUSINESS = 'BUSINESS';
    case PERSONAL = 'PERSONAL';

    public static function default(): self
    {
        return self::BUSINESS;
    }
}
