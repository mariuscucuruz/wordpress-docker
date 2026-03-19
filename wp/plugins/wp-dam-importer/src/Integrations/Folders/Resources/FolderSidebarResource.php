<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders\Resources;

use MariusCucuruz\DAMImporter\Integrations\Folders\Models\Folder;
use Illuminate\Http\Resources\Json\JsonResource;

class FolderSidebarResource extends JsonResource
{
    public $resource = Folder::class;

    public function toArray($request): array
    {
        parent::toArray($request);

        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'parent_id' => $this->parent_id,
            'albums'    => $this->albums,
            'children'  => $this->children,
        ];
    }
}
