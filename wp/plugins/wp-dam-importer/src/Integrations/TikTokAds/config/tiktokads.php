<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'TikTok Ads',
    'description'  => 'Sync your assets from your TikTok for Business account, into Medialake.',
    'active'       => env('TIKTOK_ADS_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'docs/readme.md',
    'logo'         => 'tiktokads.svg',
    'redirect_uri' => env('TIKTOK_ADS_REDIRECT', url(path: '/tiktokads-redirect', secure: true)),

    'base_url' => 'https://business-api.tiktok.com/open_api/v1.3',

    'max_asset_retry' => 10,

    'client_key'    => env('TIKTOK_ADS_CLIENT_KEY'),
    'client_secret' => env('TIKTOK_ADS_CLIENT_SECRET'),
    'client_scopes' => [
        // https://developers.tiktok.com/doc/tiktok-api-scopes
        'user.info',
        'user.info.basic',
        'user.info.profile',
        'user.info.username',
        'user.info.stats',
        'file.info',             // Media access (!!!)
        'advertiser.info.basic', // Basic advertiser info
        'advertiser.info',       // Full advertiser info
        'campaign.info',         // Campaign data access
        'ad.info',               // Ad data access
        'creative.info',         // Creative/asset data access
        'reporting.info',        // Reporting data (optional)
        'video.list',
        'video.insights',
    ],

    'settings_required' => env('TIKTOK_ADS_SETTINGS_REQUIRED', true),

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_DAY,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings' => [
        'TIKTOK_ADS_CLIENT_KEY' => [
            'name'        => 'TIKTOK_ADS_CLIENT_KEY',
            'description' => 'Enter your TikTok Ads Client Key (App ID) here without any spaces',
            'placeholder' => 'TikTok Ads Client Key',
            'type'        => 'text',
            'rules'       => 'sometimes|numeric',
        ],
        'TIKTOK_ADS_CLIENT_SECRET' => [
            'name'        => 'TIKTOK_ADS_CLIENT_SECRET',
            'description' => 'Enter your TikTok Ads Client Secret here without any spaces',
            'placeholder' => 'TikTok Ads Secret',
            'type'        => 'text',
            'rules'       => 'sometimes|alpha_dash',
        ],
    ],

    'default_fields' => [
        'user' => [
            // https://developers.tiktok.com/doc/tiktok-api-v1-user-info
            'union_id',
            'open_id',
            'display_name',
            'username',
            'bio_description',
            'profile_deep_link',
            'is_verified',
            'video_count',
            'avatar_url',
            'avatar_url_100', // 100x100 size
            'avatar_large_url', // higher resolution
        ],
        'video' => [
            // https://developers.tiktok.com/doc/tiktok-api-v2-video-object
            'video_id',
            'title',
            'width',
            'height',
            'duration',
            'share_url',
            'cover_image_url',
            'play_url',
            'file_name',
            'video_description',
            'comment_count',
            'share_count',
            'like_count',
            'view_count',
            'create_time',
        ],
        'image' => [
            // https://developers.tiktok.com/doc/tiktok-api-v2-video-object
            'image_id',
            'material_id',
            'is_carousel_usable',
            'format',
            'image_url',
            'width',
            'height',
            'signature',
            'size',
            'file_name',
            'displayable',
            'request_id',
            'create_time',
            'modify_time',
        ],
        'campaign' => [
            'campaign_id',
            'campaign_name',
        ],
        'adGroup' => [
            'advertiser_id',
            'campaign_id',
            'adgroup_id',
            'adgroup_name',
            'app_id',
            'store_id',
            'pixel_id',
            'skip_learning_phase',
            'catalog_id',
            'schedule_id',
            'creative_material_mode',
            'bid_strategy',
            'bid_price',
            'conversion_bid_price',
            'optimization_goal',
            'optimization_event',
            'secondary_optimization_event',
            'inventory_filter_enabled',
            'promotion_type',
            'age_groups',
            'secondary_status',
            'operation_status',
            'actions',
            'video_user_actions',
            'rf_purchased_type',
            'purchased_impression',
            'purchased_reach',
            'brand_safety_type',
            'expansion_enabled',
            'adgroup_app_profile_page_state',
            'delivery_mode',
            'create_time',
            'modify_time',
        ],
        'ad' => [
            // https://business-api.tiktok.com/portal/docs?id=1735735588640770
            'ad_id',
            'advertiser_id',
            'campaign_id',
            'adgroup_id',
            'tiktok_item_id',
            'card_id',
            'ad_name',
            'status',
            'video_ids',
            'image_ids',
            'music_id',
            'vehicle_ids',
            'secondary_status',
            'operation_status',
            'profile_image_url',
            'deeplink',
            'deeplink_type',
            'vast_moat_enabled',
            'creative_authorized',
            'shopping_ads_fallback_type',
            'shopping_deeplink_type',
            'shopping_ads_video_package_id',
            'product_specific_type',
            'catalog_id',
            'item_group_ids',
            'product_set_id',
            'sku_ids',
            'create_time',
            'modify_time',
        ],
        'adAccount' => [
            // https://business-api.tiktok.com/portal/docs?id=1739593083610113
            'name',
            'email',
            'address',
            'contacter',
            'advertiser_id',
            'owner_bc_id',
            'telephone_number',
            'cellphone_number',
            'country',
            'currency',
            'language',
            'role',
            'company',
            'company_name_editable',
            'status',
            'industry',
            'description',
            'rejection_reason',
            'license_no',
            'license_url',
            'balance',
            'timezone',
            'display_timezone',
            'create_time',
        ],
    ],
];
