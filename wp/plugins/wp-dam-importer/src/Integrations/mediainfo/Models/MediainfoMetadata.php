<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediainfo\Models;

use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\MediainfoMetadataFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MediainfoMetadata extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'mediainfo_metadata';

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    protected static function newFactory()
    {
        return MediainfoMetadataFactory::new();
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
