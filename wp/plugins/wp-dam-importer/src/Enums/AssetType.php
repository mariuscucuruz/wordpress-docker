<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

use Illuminate\Support\Collection;

enum AssetType: string
{
    case PDF = 'pdf';
    case CSV = 'csv';
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Illustrator = 'ai';

    public static function getType(string $type): self
    {
        return collect(self::cases())
            ->first(fn (self $case) => str_contains(strtolower($type), $case->value));
    }

    public static function names(): Collection
    {
        return collect(self::cases())
            ->map(fn (self $case) => $case->name);
    }

    public static function values(): Collection
    {
        return collect(self::cases())
            ->map(fn (self $case) => $case->value);
    }

    public static function images(): Collection
    {
        return collect([
            self::Image->value,
        ]);
    }

    public static function videos(): Collection
    {
        return collect([
            self::Video->value,
        ]);
    }

    public static function documents(): Collection
    {
        return collect([
            self::Illustrator->value,
            self::CSV->value,
            self::PDF->value,
        ]);
    }

    public static function medias(): Collection
    {
        return collect([
            self::Audio->value,
            self::Video->value,
        ]);
    }

    public static function viewables(): Collection
    {
        return collect([
            self::Image->value,
            self::Video->value,
        ]);
    }

    public static function playable(): Collection
    {
        return collect([
            self::Image->value,
            self::Video->value,
            self::Audio->value,
        ]);
    }

    public static function imagickables(): Collection
    {
        return collect([
            self::Image->value,
            self::PDF->value,
            self::Illustrator->value,
        ]);
    }

    public static function imagickableCases(): Collection
    {
        return collect([
            self::Image,
            self::PDF,
            self::Illustrator,
        ]);
    }

    public static function isImagickable(string $type): bool
    {
        return self::imagickables()->values()->contains($type);
    }

    public static function isDocument(string $type): bool
    {
        return self::documents()->contains(strtolower($type));
    }

    public static function isImage(string $type): bool
    {
        return self::images()->values()->contains($type);
    }

    public static function isVideo(string $type): bool
    {
        return self::videos()->values()->contains($type);
    }

    public static function isViewable(?string $type = null): bool
    {
        if (! $type) {
            return false;
        }

        return self::viewables()->values()->contains($type);
    }
}
