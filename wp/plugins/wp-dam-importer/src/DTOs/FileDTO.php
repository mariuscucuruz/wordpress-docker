<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\DTOs;

class FileDTO
{
    public ?string $id = null;

    public ?string $remote_service_file_id = null;

    public ?string $remote_page_identifier = null;

    public ?string $user_id = null;

    public ?string $team_id = null;

    public ?string $service_id = null;

    public ?string $service_name = null;

    public ?string $import_group = null;

    public ?string $size = null;

    public ?string $name = null;

    public ?string $slug = null;

    public ?string $thumbnail = null;

    public ?string $extension = null;

    public ?string $mime_type = null;

    public ?string $type = null;

    public ?string $created_time = null;

    public ?string $modified_time = null;

    public function __construct(protected array $properties = []) {}

    public function __get(string $name): mixed
    {
        return $this->properties[$name] ?? null;
    }

    public function toArray(): array
    {
        return $this->properties;
    }
}
