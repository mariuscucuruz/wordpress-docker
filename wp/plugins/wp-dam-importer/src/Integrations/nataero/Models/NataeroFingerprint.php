<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Models;

use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\NataeroFingerprintFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class NataeroFingerprint extends Model
{
    use HasFactory,
        HasUuids;

    protected static function newFactory(): Factory
    {
        return NataeroFingerprintFactory::new();
    }

    public function nataeroTask(): BelongsTo
    {
        return $this->belongsTo(NataeroTask::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
