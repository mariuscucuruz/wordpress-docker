<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\RekognitionTask;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait Filable
{
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function rekognitionTask(): BelongsTo
    {
        return $this->belongsTo(RekognitionTask::class);
    }
}
