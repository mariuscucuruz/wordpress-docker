<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Metaads\DTOs;

use MariusCucuruz\DAMImporter\Integrations\Metaads\Enums\MetaAdsAdType;

class MetaAdCreativeDTO
{
    public function __construct(
        public ?string $remoteIdentifier,
        public ?string $remoteParentCreativeIdentifier,
        public ?string $name,
        public ?string $socialPostIdentifier,
        public ?MetaAdsAdType $type,
    ) {}

    public static function fromApi(array $body): ?self
    {
        $remoteIdentifier = self::getCreativeIdentifier($body);
        $socialIdentifier = self::getSocialIdentifier($body);

        if (is_null($remoteIdentifier) && is_null($socialIdentifier)) {
            return null;
        }

        return new self(
            remoteIdentifier: $remoteIdentifier,
            remoteParentCreativeIdentifier: data_get($body, 'id'),
            name: data_get($body, 'name'),
            socialPostIdentifier: $socialIdentifier, // Need to look into this further with new data.
            type: self::getCreativeType($body),
        );
    }

    public static function getAdType(array $body): ?MetaAdsAdType
    {
        if (self::isCarousel($body)) {
            return MetaAdsAdType::CAROUSEL;
        }

        if (self::hasDynamicVideos($body)) {
            return MetaAdsAdType::DYNAMIC_VIDEO;
        }

        if (self::hasDynamicImages($body)) {
            return MetaAdsAdType::DYNAMIC_IMAGE;
        }

        if (self::isCreativeVideo($body)) {
            return MetaAdsAdType::VIDEO;
        }

        if (self::isCreativeImage($body)) {
            return MetaAdsAdType::IMAGE;
        }

        return null;
    }

    public static function isCarousel(array $body): bool
    {
        return filled(self::getChildAttachments($body));
    }

    public static function hasDynamicVideos(array $body): bool
    {
        return filled(data_get($body, 'asset_feed_spec.videos'));
    }

    public static function getDynamicVideos(array $body): array
    {
        return data_get($body, 'asset_feed_spec.videos') ?? [];
    }

    public static function hasDynamicImages(array $body): bool
    {
        return filled(data_get($body, 'asset_feed_spec.images'));
    }

    public static function getDynamicImages(array $body): array
    {
        return data_get($body, 'asset_feed_spec.images') ?? [];
    }

    public static function getChildAttachments(array $body): array
    {
        return data_get($body, 'object_story_spec.link_data.child_attachments') ?? [];
    }

    public static function getCreativeIdentifier(array $body): ?string
    {
        return self::getVideoIdentifier($body) ?? self::getImageIdentifier($body);
    }

    public static function isCreativeImage(array $body): bool
    {
        return ! self::isCreativeVideo($body) && filled(self::getImageIdentifier($body));
    }

    public static function getImageIdentifier(array $body): ?string
    {
        return data_get($body, 'image_hash') ?? data_get($body, 'object_story_spec.link_data.image_hash') ?? data_get($body, 'hash');
    }

    public static function getSocialIdentifier(array $body): ?string
    {
        // Method still WIP until we have a bigger dataset to test against.
        return data_get($body, 'object_type') === 'PHOTO' ? data_get($body, 'effective_object_story_id') : null;
    }

    public static function isCreativeVideo(array $body): bool
    {
        return filled(self::getVideoIdentifier($body));
    }

    public static function getVideoIdentifier(array $body): ?string
    {
        return data_get($body, 'video_id') ?? data_get($body, 'object_story_spec.video_data.video_id');
    }

    public static function getCreativeType(array $body): ?MetaAdsAdType
    {
        if (self::isCreativeVideo($body)) {
            return MetaAdsAdType::VIDEO;
        }

        if (self::isCreativeImage($body)) {
            return MetaAdsAdType::IMAGE;
        }

        return null;
    }
}
