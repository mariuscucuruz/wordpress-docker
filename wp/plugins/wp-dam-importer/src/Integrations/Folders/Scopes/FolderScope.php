<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders\Scopes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;
use MariusCucuruz\DAMImporter\Integrations\Folders\Enums\CollectionVisibilityStatus;

class FolderScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        $builder->whereNot('status', CollectionVisibilityStatus::ARCHIVED);
    }
}
