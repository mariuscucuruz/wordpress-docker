<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Models;

use Database\Factories\EmotionFactory;
use Illuminate\Database\Eloquent\Model;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits\Filable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Emotion extends Model
{
    use Filable,
        HasFactory;

    public static function topLevelCategories(): array
    {
        return [
            ['name' => 'Happy', 'count' => 0],
            ['name' => 'Sad', 'count' => 0],
            ['name' => 'Angry', 'count' => 0],
            ['name' => 'Confused', 'count' => 0],
            ['name' => 'Disgusted', 'count' => 0],
            ['name' => 'Surprised', 'count' => 0],
            ['name' => 'Calm', 'count' => 0],
            ['name' => 'Fear', 'count' => 0],
            ['name' => 'Unknown', 'count' => 0],
        ];
    }

    protected function casts(): array
    {
        return [
            'items' => 'json',
        ];
    }

    protected static function newFactory(): Factory
    {
        return EmotionFactory::new();
    }
}
