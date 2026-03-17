<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Models\Scopes;

use MariusCucuruz\DAMImporter\Enums\FileVisibilityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;

class FileScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder
            ->whereNull('parent_id')
            ->whereNot('visibility', FileVisibilityStatus::ARCHIVED);
    }
}
