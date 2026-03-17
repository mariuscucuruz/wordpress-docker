<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders\Resources;

use MariusCucuruz\DAMImporter\Integrations\Folders\Models\Album;
use Illuminate\Http\Resources\Json\JsonResource;

class AlbumSimpleResource extends JsonResource
{
    public $resource = Album::class;

    public function toArray($request): array
    {
        parent::toArray($request);

        return [
            'id'   => $this->id,
            'name' => $this->name,
            'type' => $this->type,
        ];
    }
}
