<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Models;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\Service;
use Pgvector\Laravel\Vector;
use Illuminate\Database\Eloquent\Model;
use MariusCucuruz\DAMImporter\Integrations\Multimodal\Traits\Similarity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hyper1VideoEmbedding extends Model
{
    use HasUuids;
    use Similarity;

    public const int VECTOR_DIMENSION = 256;

    protected $casts = [
        'embedding' => Vector::class,
    ];

    public function nataeroTask(): BelongsTo
    {
        return $this->belongsTo(NataeroTask::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
