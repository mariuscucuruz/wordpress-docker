<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use MariusCucuruz\DAMImporter\Models\Status;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** @deprecated  */
trait Statusable
{
    /** @deprecated */
    public function statuses(): MorphMany
    {
        return $this->morphMany(Status::class, 'statusable');
    }
}
