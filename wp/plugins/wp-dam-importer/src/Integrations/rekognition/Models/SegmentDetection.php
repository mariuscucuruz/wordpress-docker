<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Models;

use Illuminate\Database\Eloquent\Model;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits\Filable;
use Database\Factories\SegmentDetectionFactory;
use MariusCucuruz\DAMImporter\Integrations\Multimodal\Models\VideoEmbedding;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SegmentDetection extends Model
{
    use Filable;
    use HasFactory;
    use HasUuids;

    protected static function newFactory(): SegmentDetectionFactory|Factory
    {
        return SegmentDetectionFactory::new();
    }

    public function embedding(): HasOne
    {
        return $this->hasOne(VideoEmbedding::class, 'segment_detection_id');
    }
}
