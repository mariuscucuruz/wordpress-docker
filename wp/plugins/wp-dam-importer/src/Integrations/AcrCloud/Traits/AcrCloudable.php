<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Models\AcrCloudMusicTrack;

trait AcrCloudable
{
    public function acrCloudMusicTracks(): HasMany
    {
        return $this->hasMany(AcrCloudMusicTrack::class);
    }
}
