<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Models;

use Database\Factories\FaceFactory;
use Illuminate\Database\Eloquent\Model;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits\Filable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Face extends Model
{
    use Filable,
        HasFactory;

    protected function casts(): array
    {
        return [
            'items' => 'json',
        ];
    }

    protected static function newFactory(): Factory
    {
        return FaceFactory::new();
    }
}
