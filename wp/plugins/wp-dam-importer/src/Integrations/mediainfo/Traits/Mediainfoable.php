<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediainfo\Traits;

use Illuminate\Database\Eloquent\Relations\HasOne;
use MariusCucuruz\DAMImporter\Integrations\Mediainfo\Models\MediainfoMetadata;

trait Mediainfoable
{
    public function mediainfoMetadata(): HasOne
    {
        return $this->hasOne(MediainfoMetadata::class, 'file_id');
    }
}
