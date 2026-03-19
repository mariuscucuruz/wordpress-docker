<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sneakpeek\Traits;

use MariusCucuruz\DAMImporter\Integrations\Sneakpeek\Models\Sneakpeek;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Sneakpeekable
{
    public function sneakpeeks(): MorphMany
    {
        return $this->morphMany(Sneakpeek::class, 'sneakpeekable');
    }
}
