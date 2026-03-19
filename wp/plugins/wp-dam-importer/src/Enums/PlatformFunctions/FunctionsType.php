<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums\PlatformFunctions;

use Closure;
use Illuminate\Support\Collection;

enum FunctionsType: string
{
    case Video = 'video';
    case Image = 'image';
    case Audio = 'audio';
    case PDF = 'pdf';
    case Illustrator = 'ai';

    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function getType(?string $type): Closure|self|null
    {
        if (empty($type)) {
            return null;
        }

        return collect(self::cases())
            ->first(fn (self $case) => str_contains(strtolower($type), $case->value));
    }

    public static function imagickableTypes(): Collection
    {
        return collect([
            self::Illustrator->value,
            self::Image->value,
            self::PDF->value,
        ]);
    }

    public static function hasActionMethods(string $type): bool
    {
        return collect([
            self::Image->value,
            self::Video->value,
            self::Audio->value,
        ])->contains($type);
    }

    public static function acrCanProcess(string $type): bool
    {
        return in_array(strtolower($type), [self::Audio->value, self::Video->value], true);
    }
}
