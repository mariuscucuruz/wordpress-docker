<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'metaads',
    'description'  => 'Ads hosted across Meta platforms, including Facebook, Instagram, and more.',
    'logo'         => 'metaads.svg',
    'active'       => env('META_ADS_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'oauth_base_url' => 'https://www.facebook.com/',
    'query_base_url' => 'https://graph.facebook.com/',

    'version' => 'v21.0',

    'client_id'     => env('META_ADS_CLIENT_ID'),
    'client_secret' => env('META_ADS_SECRET'),
    'config_id'     => env('META_ADS_CONFIG_ID'),
    'redirect_uri'  => env('META_ADS_REDIRECT')
        ? url(path: env('META_ADS_REDIRECT'), secure: true)
        : url(path: 'oauth2.medialakeapp.com/metaads-redirect', secure: true),

    'scope' => 'ads_read',

    'settings_required' => env('META_ADS_SETTINGS_REQUIRED', true),

    'limit_per_request' => 100,

    'batch_request_max_size' => 25,

    'rate_limit' => env('META_ADS_RATE_LIMIT', 200),

    'ad_account_fields' => 'id,name',

    'ad_accounts_marketing_fields' => 'name,' .
        'campaign{id,name},' .
        'ad{id,name},' .
        'adset{id,name},' .
        'creative{' .
            'id,name,object_type,object_story_id,effective_object_story_id,' .
            'object_story_spec{' .
            'video_data{video_id},' . // thumbnail_url can help with debugging but not available on all creatives.
            'link_data{image_hash,child_attachments{video_id,image_hash}}' .
            '},' .
        'asset_feed_spec' .
        '}',

    'performance_fields' => 'reach,video_p50_watched_actions,video_p25_watched_actions,impressions,video_avg_time_watched_actions,actions,spend,account_currency,video_time_watched_actions,conversions,video_play_actions,cost_per_action_type,cost_per_conversion,purchase_roas,full_view_impressions,ctr,cpc,cpm,frequency',

    'metadata_fields' => [
        'video' => ['id', 'source', 'title', 'status', 'permalink_url', 'picture', 'creatives'],
        'image' => ['id', 'hash', 'permalink_url', 'url', 'name', 'status', 'permalink_url', 'url', 'creatives'],
    ],

    'video_fields' => 'id,source,title,created_time,updated_time,status,permalink_url,picture,thumbnails,creatives',
    'image_fields' => 'id,status,created_time,hash,creatives,permalink_url,updated_time,url,name',

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
        'META_ADS_CLIENT_ID' => [
            'name'        => 'META_ADS_CLIENT_ID',
            'placeholder' => 'Enter your Meta Ads Client ID here without any space',
            'description' => 'Meta Ads Client ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'META_ADS_SECRET' => [
            'name'        => 'META_ADS_SECRET',
            'placeholder' => 'Enter your Meta Ads Secret here without any space',
            'description' => 'Meta Ads Client Secret',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'META_ADS_CONFIG_ID' => [
            'name'        => 'META_ADS_CONFIG_ID',
            'placeholder' => 'Enter your Meta Ads Config ID here without any space',
            'description' => 'Meta Ads Config ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
