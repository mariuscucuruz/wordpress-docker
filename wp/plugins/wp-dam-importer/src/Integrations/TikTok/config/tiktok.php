<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'tiktok',
    'description'  => 'A personalized short-form video feed based on what you watch, like, and share.',
    'logo'         => 'tiktok.svg',
    'active'       => env('TIKTOK_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'oauth_base_url' => 'https://www.tiktok.com/v2/auth/authorize/',
    'query_base_url' => 'https://open.tiktokapis.com/v2/',
    'redirect_uri'   => env('TIKTOK_REDIRECT')
        ? url(path: env('TIKTOK_REDIRECT'), secure: true)
        : url(path: 'oauth2.medialakeapp.com/tiktok-redirect', secure: true),

    'client_key'    => env('TIKTOK_CLIENT_KEY'),
    'client_secret' => env('TIKTOK_SECRET'),
    'token_url'     => 'https://open.tiktokapis.com/v2/oauth/token/',

    'per_page' => 20, // max

    'list_video_fields' => 'cover_image_url,id,title,create_time,share_url,video_description,duration,embed_html,embed_link,like_count,comment_count,share_count,view_count',
    'metadata_fields'   => [
        'video_description' => 'description',
        'share_url'         => 'share_url',
        'embed_link'        => 'source_link',
        'like_count'        => 'like_count',
        'comment_count'     => 'comment_count',
        'share_count'       => 'share_count',
        'view_count'        => 'view_count',
    ],

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_DAY,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings_required' => env('TIKTOK_SETTINGS_REQUIRED', true),

    'settings' => [
        'TIKTOK_CLIENT_KEY' => [
            'name'        => 'TIKTOK_CLIENT_KEY',
            'placeholder' => 'Enter your Tiktok Client ID here without any space',
            'description' => 'Tiktok Client ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'TIKTOK_SECRET' => [
            'name'        => 'TIKTOK_SECRET',
            'placeholder' => 'Enter your Tiktok Secret here without any space',
            'description' => 'Tiktok Secret',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
