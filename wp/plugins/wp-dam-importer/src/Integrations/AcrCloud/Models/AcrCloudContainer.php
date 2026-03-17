<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Models;

use Illuminate\Database\Eloquent\Model;
use Database\Factories\AcrCloudContainerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AcrCloudContainer extends Model
{
    use HasFactory,
        HasUuids;

    protected static function newFactory()
    {
        return AcrCloudContainerFactory::new();
    }
}
