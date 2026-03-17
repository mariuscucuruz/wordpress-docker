<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Models;

use Illuminate\Database\Eloquent\Model;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits\Filable;
use Database\Factories\ModerationDetectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ModerationDetection extends Model
{
    use Filable;
    use HasFactory;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'instances'        => 'json',
            'image_properties' => 'json',
            'bounding_box'     => 'json',
        ];
    }

    // SEE: https://docs.aws.amazon.com/rekognition/latest/dg/moderation.html
    public static function topLevelCategories(): array
    {
        return [
            ['name' => 'Explicit', 'count' => 0],
            ['name' => 'Non-Explicit Nudity of Intimate parts and Kissing', 'count' => 0],
            ['name' => 'Swimwear or Underwear', 'count' => 0],
            ['name' => 'Violence', 'count' => 0],
            ['name' => 'Visually Disturbing', 'count' => 0],
            ['name' => 'Drugs & Tobacco', 'count' => 0],
            ['name' => 'Alcohol', 'count' => 0],
            ['name' => 'Rude Gestures', 'count' => 0],
            ['name' => 'Gambling', 'count' => 0],
            ['name' => 'Hate Symbols', 'count' => 0],
        ];
    }

    protected static function newFactory(): Factory
    {
        return ModerationDetectionFactory::new();
    }
}
