<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Models\Scopes;

use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;

class ServiceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNot('status', IntegrationStatus::ARCHIVED);
    }
}
