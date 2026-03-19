<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Models;

use Illuminate\Database\Eloquent\Model;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits\Filable;
use Database\Factories\LandmarkDetectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LandmarkDetection extends Model
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

    protected static function newFactory()
    {
        return LandmarkDetectionFactory::new();
    }
}
