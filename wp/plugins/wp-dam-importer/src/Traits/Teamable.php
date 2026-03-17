<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use MariusCucuruz\DAMImporter\Models\Scopes\TeamScope;

trait Teamable
{
    public static function bootTeamable(): void
    {
        static::addGlobalScope(new TeamScope);
        static::creating(function ($model) {
            if (auth()->check()) {
                $model->team_id = auth()->user()->currentTeam?->id;
            }
        });
    }
}
