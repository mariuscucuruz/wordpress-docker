<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds\DTOs;

use MariusCucuruz\DAMImporter\DTOs\FolderEntryDTO;

class CampaignDto extends FolderEntryDTO
{
    public array $properties = [];

    public ?string $id = null;

    public ?string $name = null;

    public ?bool $isDir = true;

    private ?string $owner;

    public ?array $items = [];

    public static function fromArray(iterable $board): ?self
    {
        if (filled($board)) {
            return new self($board);
        }

        return null;
    }

    public function __construct(iterable $properties = [])
    {
        parent::__construct($properties);

        $this->isDir = true;
        $this->id = (string) data_get($properties, 'id', 0);
        $this->name = (string) data_get($properties, 'name', '');
        $this->owner = (string) data_get($properties, 'owner.username', '');
        $this->items = array_filter([
            data_get($properties, 'media.image_cover_url'),
        ]);

        $this->properties = [
            'isDir'           => $this->isDir,
            'name'            => $this->name,
            'owner'           => $this->owner,
            'items'           => $this->items,
            'folder_id'       => $this->id,
            'image_cover_url' => data_get($properties, 'media.image_cover_url'),
        ];
    }

    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'isDir'           => $this->isDir,
            'items'           => $this->items,
            'name'            => $this->name,
            'owner'           => $this->owner,
            'folder_id'       => $this->id,
            'image_cover_url' => data_get($this->properties, 'image_cover_url'),
        ];
    }
}
