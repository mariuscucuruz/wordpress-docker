<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\DTOs;

class UserDTO
{
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
