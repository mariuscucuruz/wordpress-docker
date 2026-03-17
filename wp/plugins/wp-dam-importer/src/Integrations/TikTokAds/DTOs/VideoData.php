<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds\DTOs;

use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class VideoData extends BaseDTO
{
    /**
     * [
     * "allow_download": true,
     * "allowed_placements": [
     *    "PLACEMENT_TOPBUZZ",
     *    "PLACEMENT_TIKTOK",
     *    "PLACEMENT_HELO",
     *    "PLACEMENT_PANGLE",
     *    "PLACEMENT_GLOBAL_APP_BUNDLE"
     * ],
     * "bit_rate": 640423,
     * "create_time": "2025-08-22T09:47:05Z",
     * "displayable": true,
     * "duration": 5.035,
     * "file_name": "ML logo (1)_uE78IqVW.mp4",
     * "format": "mp4",
     * "height": 1920,
     * "material_id": "7541344179588087824",
     * "modify_time": "2025-08-22T09:47:05Z",
     * "preview_url": "https://v16m-default.tiktokcdn.com/6d8d7e294f43c8a6bb36820819d4db02/68b7343e/video/tos/alisg/tos-alisg-ve-0051c001-sg/oQHMoAMEDQEl3EEoGqFfBBgWJPVNyfIiNtDsDT/?a=0&bti=Nzg3NWYzLTQ6&ch=0&cr=0&dr=0&cd=0%7C0%7C0%7C0&cv=1&br=960&bt=480&cs=0&ds=4&ft=cApXJCz7ThWHGx2NEGZmo0P&mime_type=video_mp4&qs=0&rc=Z2dlZjo6Zmg8aDk8NWY4OkBpajdoanA5cjc2NTMzODYzNEBiNF81NjY1Ni0xXjJjYzI0YSNzYHM1MmRzbWhhLS1kMC1zcw%3D%3D&vvpl=1&l=202509022015217A1F633754F2A62A774A&btag=e000b0000",
     * "preview_url_expire_time": "2025-09-02 18:15:21",
     * "signature": "72bd9c013ca62dde3f6f64ab5006e22c",
     * "size": 403040,
     * "video_cover_url": "http://p16-sign-sg.tiktokcdn.com/tos-alisg-p-0051c001-sg/oIfAHQanoZRAeLcPP7IMCLUGbDJGEAkguIeqYT~tplv-noop.image?t=9276707c&x-expires=1756836926&x-signature=MLZfu3gQW%2FTGPU%2FqC7c%2FLG%2BXMVE%3D",
     * "video_id": "v10033g50000d2k3p4vog65m8gvec4vg",
     * "width": 1080
     **/
    public string $type = 'video';

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

    public null|int|float|string $duration = null;

    public null|int|string $size = null;

    public null|int|string $height = null;

    public null|int|string $width = null;

    public mixed $createdDate = null;

    public mixed $updatedDate = null;

    // public ?array $meta = [];

    public static function makeFromHttpResponse(iterable $videoMeta): static
    {
        return static::fromArray([
            'file_id'      => data_get($videoMeta, 'video_id'),
            'signature'    => data_get($videoMeta, 'signature'),
            'file_name'    => data_get($videoMeta, 'file_name') ?? str()->random(10) . '.mp4',
            'url'          => data_get($videoMeta, 'preview_url'),
            'thumbnail'    => data_get($videoMeta, 'video_cover_url'),
            'extension'    => data_get($videoMeta, 'format', 'mp4'),
            'mime_type'    => 'video/' . data_get($videoMeta, 'format', 'mp4'),
            'duration'     => data_get($videoMeta, 'duration'),
            'size'         => data_get($videoMeta, 'size'),
            'height'       => data_get($videoMeta, 'height'),
            'width'        => data_get($videoMeta, 'width'),
            'created_date' => data_get($videoMeta, 'create_time') ?? null,
            'updated_date' => data_get($videoMeta, 'modify_time') ?? null,
        ]);
    }
}
