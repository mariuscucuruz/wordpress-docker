<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Metaads\DTOs;

use MariusCucuruz\DAMImporter\Integrations\Metaads\Enums\MetaAdsAdType;

class MetaAdMarketingStructureDTO
{
    public function __construct(
        public ?string $adAccountUuid,
        public ?string $remoteAdAccountIdentifier,
        public ?string $remoteCampaignIdentifier,
        public ?string $campaignName,
        public ?string $remoteAdsetIdentifier,
        public ?string $adsetName,
        public ?string $adRemoteIdentifier,
        public ?string $adName,
        public ?MetaAdsAdType $adType,
        public ?array $creativeBody,
    ) {}

    public static function fromApi(array $adItem): self
    {
        return new self(
            adAccountUuid: data_get($adItem, 'adAccountUuid'),
            remoteAdAccountIdentifier: data_get($adItem, 'remoteAdAccountIdentifier'),
            remoteCampaignIdentifier: data_get($adItem, 'campaign.id'),
            campaignName: data_get($adItem, 'campaign.name'),
            remoteAdsetIdentifier: data_get($adItem, 'adset.id'),
            adsetName: data_get($adItem, 'adset.name'),
            adRemoteIdentifier: data_get($adItem, 'id'),
            adName: data_get($adItem, 'name'),
            adType: MetaAdCreativeDTO::getAdType(data_get($adItem, 'creative')),
            creativeBody: data_get($adItem, 'creative'),
        );
    }
}
