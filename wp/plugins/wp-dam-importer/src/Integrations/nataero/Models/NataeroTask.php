<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Models;

use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\NataeroTaskFactory;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Traits\Nataeroable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class NataeroTask extends Model
{
    use HasFactory;
    use HasUuids;
    use Nataeroable;

    protected static function newFactory(): Factory
    {
        return NataeroTaskFactory::new();
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
