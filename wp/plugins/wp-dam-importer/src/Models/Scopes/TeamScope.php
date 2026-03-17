<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Models\Scopes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;

class TeamScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user) {
            // No authenticated user - don't apply any filtering
            return;
        }

        $teamId = $user->current_team_id;

        if ($teamId) {
            $builder->where('team_id', $teamId);
        } else {
            // Defense-in-depth: If authenticated user has NULL current_team_id, return NO results
            // This prevents "Ghost Team" where users see all assets
            $builder->whereRaw('1 = 0');
        }
    }
}
