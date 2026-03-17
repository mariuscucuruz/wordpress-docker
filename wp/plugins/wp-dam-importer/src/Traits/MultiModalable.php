<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use Clickonmedia\Multimodal\Models\ImageEmbedding;
use Clickonmedia\Multimodal\Models\VideoEmbedding;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait MultiModalable
{
    public function imageEmbedding(): HasOne
    {
        return $this->hasOne(ImageEmbedding::class);
    }

    public function videoEmbedding(): HasMany
    {
        return $this->hasMany(VideoEmbedding::class);
    }

    public function hasEmbeddings(): bool
    {
        return $this->imageEmbedding()->exists() || $this->videoEmbedding()->exists();
    }
}
