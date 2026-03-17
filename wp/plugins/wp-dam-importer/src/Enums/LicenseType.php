<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum LicenseType: string
{
    case Talent = 'talent';
    case StockLicense = 'stock_license';
    case PropertyRelease = 'property_release';
    case BrandLicense = 'brand_license';

    public static function inertiaValues(): array
    {
        return array_values(
            array_map(fn (self $type) => $type->value, self::cases())
        );
    }
}
