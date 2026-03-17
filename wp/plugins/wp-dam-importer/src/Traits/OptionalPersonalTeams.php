<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use MariusCucuruz\DAMImporter\Models\Team;
use MariusCucuruz\DAMImporter\Models\AdminSetting;
use Laravel\Jetstream\HasTeams;

trait OptionalPersonalTeams
{
    use HasTeams;

    public function switchTeam(?Team $team): bool
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }

        $this->forceFill([
            'current_team_id' => $team->id,
        ])->saveQuietly();

        $this->setRelation('currentTeam', $team);

        return true;
    }

    public function showDisabledPersonalTeamErrorPage(): bool
    {
        return AdminSetting::isPersonalTeamDisabled() && auth()->user()?->currentTeam?->personal_team;
    }
}
