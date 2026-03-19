<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Traits;

use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\NataeroTask;
use Illuminate\Database\Eloquent\Relations\HasOne;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\NataeroFingerprint;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\Hyper1ImageEmbedding;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\Hyper1VideoEmbedding;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\Hyper1AverageVideoEmbedding;

trait Nataeroable
{
    public function nataeroTasks(): HasMany
    {
        return $this->hasMany(NataeroTask::class);
    }

    public function nataeroFingerprints(): HasMany
    {
        return $this->hasMany(NataeroFingerprint::class);
    }

    public function nataeroScenes(): HasMany
    {
        return $this->nataeroFingerprints()->where('function_type', NataeroFunctionType::SCENE_DETECTION);
    }

    public function hyper1ImageEmbedding(): HasOne
    {
        return $this->hasOne(Hyper1ImageEmbedding::class);
    }

    public function hyper1VideoEmbedding(): HasMany
    {
        return $this->hasMany(Hyper1VideoEmbedding::class);
    }

    public function hyper1AverageVideoEmbedding(): HasOne
    {
        return $this->hasOne(Hyper1AverageVideoEmbedding::class);
    }
}
