<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Exif\Traits;

use MariusCucuruz\DAMImporter\Integrations\Exif\Models\ExifMetadata;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait Exifable
{
    public function exifMetadata(): HasOne
    {
        return $this->hasOne(ExifMetadata::class, 'file_id');
    }
}
