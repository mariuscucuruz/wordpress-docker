<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders\Traits;

use MariusCucuruz\DAMImporter\Models\Scopes\TeamScope;
use MariusCucuruz\DAMImporter\Integrations\Folders\Models\Album;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait ParentAlbums
{
    public function albums(): BelongsToMany
    {
        return $this->belongsToMany(Album::class)->withoutGlobalScope(TeamScope::class);
    }
}
