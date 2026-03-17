<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AdobeExperienceManager\DTOs;

use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Enums\ServiceDirectoryBranchStatus;

final readonly class ServiceDirectoryTreeBranchDTO
{
    public function __construct(
        public string $serviceId,
        public string $serviceName,
        public string $path,
        public string $niceName,
        public ?string $parentDirectoryId,
        public string $lastTraverseSyncStatus,
        public string $lastFileSyncStatus,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromApi(Service $service, ?string $parentBrandId, array $data): self
    {
        $now = now()->toDateTimeString();
        $selfItem = collect(data_get($data, 'links') ?? [])->firstWhere('rel.0', 'self');
        $url = data_get($selfItem, 'href');
        $cleanPath = str($url)->before('?limit')->toString();
        $name = data_get($data, 'properties.name');
        $title = $name ? str(str_replace(['-', '_'], ' ', $name))->title()->toString() : null;

        return new self(
            serviceId: $service->id,
            serviceName: $service->name,
            path: $cleanPath,
            niceName: $title ?? self::getNiceNameFromPath($cleanPath),
            parentDirectoryId: $parentBrandId,
            lastTraverseSyncStatus: ServiceDirectoryBranchStatus::WAITING->value,
            lastFileSyncStatus: ServiceDirectoryBranchStatus::WAITING->value,
            createdAt: $now,
            updatedAt: $now
        );
    }

    public static function getNiceNameFromPath(string $path): string
    {
        return str($path)->afterLast('/')->beforeLast('.json')->toString();
    }

    public function toArray(): array
    {
        return [
            'service_id'                => $this->serviceId,
            'service_name'              => $this->serviceName,
            'path'                      => $this->path,
            'nice_name'                 => $this->niceName,
            'parent_directory_id'       => $this->parentDirectoryId,
            'last_traverse_sync_status' => $this->lastTraverseSyncStatus,
            'last_file_sync_status'     => $this->lastFileSyncStatus,
            'created_at'                => $this->createdAt,
            'updated_at'                => $this->updatedAt,
        ];
    }
}
