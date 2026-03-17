<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Manager;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use MariusCucuruz\DAMImporter\DTOs\IntegrationDefinition;
use MariusCucuruz\DAMImporter\Interfaces\PackageTypes\IsStoragePackage;

abstract class StoragePackageManager extends Manager implements IsStoragePackage
{
    public static function definition(): IntegrationDefinition
    {
        return IntegrationDefinition::make(
            name: static::getServiceName(),
            providerClass: static::class . 'ServiceProvider',
        );
    }

    public function prepareDirectoryStructure(File $file, ?string $directory): string
    {
        $defaultDirectory = Path::join(config('app.name'), $this->service->id);
        $fileName = "{$file->slug}.{$file->extension}";

        return Path::join($directory ?? $defaultDirectory, $fileName);
    }

    public function storageProperties(array $storage): array
    {
        $settings = $this->settings->mapWithKeys(fn ($item) => [$item['name'] => encrypt($item['payload'])]);

        return [
            'user_id'        => auth()?->id(),
            'team_id'        => auth()?->user()?->currentTeam?->id,
            'name'           => static::getServiceName(),
            'interface_type' => static::getInterfaceType(),
            'status'         => IntegrationStatus::ACTIVE,
            'ip_address'     => request()?->ip(),
            'options'        => isset($storage['options'])
                ? json_encode([...$settings, $storage['options']])
                : $settings->toJson(),
        ];
    }
}
