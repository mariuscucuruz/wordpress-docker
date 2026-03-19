<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum IntegrationStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case UNAUTHORIZED = 'UNAUTHORIZED';
    case ARCHIVED = 'ARCHIVED';
    case DEPRECATED = 'DEPRECATED';

    public static function inactiveCases(): array
    {
        return [
            self::INACTIVE,
            self::ARCHIVED,
            self::DEPRECATED,
        ];
    }

    public static function activeCases(): array
    {
        return [
            self::ACTIVE,
            self::UNAUTHORIZED,
        ];
    }
}
