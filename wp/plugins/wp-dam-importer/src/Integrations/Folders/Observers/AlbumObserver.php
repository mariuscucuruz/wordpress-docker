<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders\Observers;

use MariusCucuruz\DAMImporter\Integrations\Folders\Models\Album;

class AlbumObserver
{
    public function creating(Album $album): void
    {
        $album->team_id = auth()->user()?->currentTeam?->id;
        $album->save();
    }
}
