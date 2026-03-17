<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum RegionType: string
{
    case Region = 'region';
    case SubRegion = 'sub_region';
    case IntermediateRegion = 'intermediate_region';
    case Country = 'country';
    case CustomRegion = 'custom_region';

    public static function options(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn (self $case) => ucwords(str_replace('_', ' ', $case->value)), self::cases())
        );
    }
}
