<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds\DTOs;

use DateTime;
use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class ImageData extends BaseDTO
{
    /**
     * {
     * "image_id": "ad-site-i18n-sg/202508225d0dcf3c6cfde45c456e83e1",
     * "image_url": "https://p19-ad-site-sign-sg.ibyteimg.com/ad-site-i18n-sg/202508225d0dcf3c6cfde45c456e83e1~tplv-d5opwmad15-image.jpeg?rk3s=623c3a84&x-expires=1756819846&x-signature=tXJ3GXJVM8Dc2UfZIXUVKehxanc%3D",
     * "material_id": "7541342271449186305",
     * "signature": "3c5bf0de95948e2f8038ca8343a374b7",
     * "displayable": false,
     * "file_name": "ML Logo 1_cKzqEtZY.png",
     * "format": "jpeg",
     * "size": 790028,
     * "height": 1280,
     * "width": 720
     * "is_carousel_usable": true,
     * "create_time": "2025-08-22T09:39:41Z",
     * "modify_time": "2025-08-22T09:39:41Z",
     * }
     */
    public string $type = 'image';

    public string|int|null $fileId = null;

    // public string|int|null $adGroupId = null;

    // public string|int|null $adId = null;

    // public string|int|null $campaignId = null;

    // public string|int|null $advertiserId = null;

    public ?string $signature = null;

    public ?string $fileName = null;

    public ?string $title = null;

    public ?string $url = null;

    public ?string $thumbnail = null;

    public ?string $extension = null;

    public ?string $mimeType = null;

    public null $duration = null;

    public null|int|string $size = null;

    public null|int|string $height = null;

    public null|int|string $width = null;

    public ?DateTime $updatedDate = null;

    public ?DateTime $createdDate = null;

    // public ?array $meta = [];

    public static function makeFromHttpResponse(iterable $imgMeta): static
    {
        return static::fromArray([
            'file_id'      => data_get($imgMeta, 'image_id'),
            'signature'    => data_get($imgMeta, 'signature'),
            'file_name'    => data_get($imgMeta, 'file_name') ?? str()->random(10) . '.png',
            'url'          => data_get($imgMeta, 'image_url'),
            'thumbnail'    => data_get($imgMeta, 'image_url'),
            'extension'    => data_get($imgMeta, 'format', 'png'),
            'mime_type'    => 'image/' . data_get($imgMeta, 'format', 'png'),
            'size'         => data_get($imgMeta, 'size'),
            'height'       => data_get($imgMeta, 'height'),
            'width'        => data_get($imgMeta, 'width'),
            'created_date' => data_get($imgMeta, 'create_time') ?? null,
            'updated_date' => data_get($imgMeta, 'modify_time') ?? null,
        ]);
    }
}
