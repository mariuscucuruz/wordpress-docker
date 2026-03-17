<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders\Resources;

use MariusCucuruz\DAMImporter\Integrations\Folders\Models\Folder;
use Illuminate\Http\Resources\Json\JsonResource;

class FolderDetailResource extends JsonResource
{
    public $resource = Folder::class;

    public function toArray($request): array
    {
        parent::toArray($request);

        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'status'     => $this->status,
            'type'       => $this->type,
            'user_id'    => $this->user_id,
            'parent_id'  => $this->parent_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'user'       => $this->user,
            'parent'     => $this->parent,
            'children'   => self::collection($this->children),
        ];
    }
}
