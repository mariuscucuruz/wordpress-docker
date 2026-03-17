<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum LicenseRightType: int
{
    case NotSpecified = 0;
    case Allowed = 1;
    case NotAllowed = 2;

    public static function values(): array
    {
        return array_values(
            array_filter(self::cases(), fn ($type) => $type->value !== self::NotSpecified->value)
        );
    }

    public static function inertiaValues(): array
    {
        return array_map(fn ($type) => [
            'id'   => $type->value,
            'name' => $type->name,
        ], self::values());
    }
}
