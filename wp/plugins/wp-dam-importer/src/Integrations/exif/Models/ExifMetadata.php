<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Exif\Models;

use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\ExifMetadataFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ExifMetadata extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'exif_metadata';

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    protected static function newFactory()
    {
        return ExifMetadataFactory::new();
    }

    public static function searchableKeys()
    {
        return [
            'MIME Type',
            'File Size',
            'File Modification Date/Time',
            'File Access Date/Time',
            'File Inode Change Date/Time',
            'Create Date',
            'Modify Date',
            'X Resolution',
            'Y Resolution',
            'Image Width',
            'Image Height',
            'Megapixels',
            'Profile Copyright',
            'Profile Description',
            'Profile Creator',
            'Device Model',
            'Make',
            'Camera Model Name',
            'Exposure Time',
            'Exposure Mode',
            'Exposure Compensation',
            'Aperture',
            'Shutter Speed',
            'White Balance',
            'Focal Length',
            'Metering Mode',
            'ISO',
            'Aspect Ratio',
            'Lens Model',
            'Image Description',
            'XP Keywords',
            'Artist',
            'Copyright',
        ];
    }
}
