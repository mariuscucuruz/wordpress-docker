<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Models;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Traits\HasOperationStates;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\AcrCloudMusicTrackFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AcrCloudMusicTrack extends Model
{
    use HasFactory,
        HasOperationStates,
        HasUuids;

    protected static function newFactory()
    {
        return AcrCloudMusicTrackFactory::new();
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
