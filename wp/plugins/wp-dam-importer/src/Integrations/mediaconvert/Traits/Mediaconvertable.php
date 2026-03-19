<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Traits;

use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Models\FileOperationState;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait Mediaconvertable
{
    public function mediaconvertOperation(): HasOne
    {
        return $this->hasOne(FileOperationState::class)
            ->where('operation_name', FileOperationName::CONVERT);
    }
}
