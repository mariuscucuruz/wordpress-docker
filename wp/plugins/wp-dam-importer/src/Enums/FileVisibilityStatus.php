<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

use Illuminate\Support\Collection;

enum FileVisibilityStatus: string
{
    case SHARED = 'SHARED';
    case PUBLIC = 'PUBLIC';
    case PRIVATE = 'PRIVATE';
    case ARCHIVED = 'ARCHIVED';

    public static function visibleCases(): Collection
    {
        return collect([
            self::SHARED,
            self::PUBLIC,
            self::PRIVATE,
        ]);
    }
}
