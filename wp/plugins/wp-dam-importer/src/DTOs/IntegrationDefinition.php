<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\DTOs;

class IntegrationDefinition
{
    public string $name;

    public string $className;
    public string $displayName;
    public string $providerClass;

    public static function make(string $name, string $displayName, string $providerClass): static
    {
        $definition = new static;

        $definition->name = $name;
        $definition->displayName = $displayName;
        $definition->providerClass = $providerClass;
        $definition->className = static::class;

        return $definition;
    }


    public function toArray(): array
    {
        if (! $config = config($this->name, [])) {
            return [];
        }

        return [
            'name'              => $this->name,
            'display_name'      => $this->displayName,
            'provider_class'    => $this->providerClass,
            'class_name'        => $this->className,
            'description'       => $config['description'] ?? null,
            'logo'              => $config['logo'] ?? null,
            'type'              => $config['type'] ?? null,
            'active'            => $config['active'] ?? null,
            'instructions'      => $config['instructions'] ?? null,
            'settings'          => $config['settings'] ?? null,
        ];
    }
}
