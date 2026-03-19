<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums\PlatformFunctions;

enum FunctionsMode: string
{
    case Automatic = 'Automatic';
    case Manual = 'Manual';
    case Disabled = 'Disabled';

    public static function names(): array
    {
        return array_column(self::cases(), 'name');
    }
}
