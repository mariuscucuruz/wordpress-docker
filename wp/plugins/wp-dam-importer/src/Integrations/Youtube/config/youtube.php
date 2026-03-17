<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\OauthAppStatus;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'youtube',
    'description'  => 'A video sharing platform for creators and viewers.',
    'logo'         => 'youtube.png',
    'active'       => env('YOUTUBE_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'project_id'    => env('YOUTUBE_PROJECT_ID'),
    'client_id'     => env('YOUTUBE_CLIENT_ID'),
    'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
    'redirect_uri'  => env('YOUTUBE_REDIRECT_URI')
        ? url(path: env('YOUTUBE_REDIRECT_URI'), secure: true)
        : url(path: 'oauth2.medialakeapp.com/youtube-redirect', secure: true),

    'auth_uri'                    => 'https://accounts.google.com/o/oauth2/auth',
    'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
    'javascript_origins'          => [env('APP_URL')],
    'access_type'                 => 'offline',
    'approval_prompt'             => 'force',
    'prompt'                      => 'select_account consent',
    'yt_dlp'                      => env('YTDLP', '/usr/local/bin/yt-dlp'),
    'per_page'                    => 50,

    'oauth_app_status' => env('YOUTUBE_OAUTH_APP_STATUS') ?? OauthAppStatus::PUBLISHED->value,

    'refresh_token_expiration' => [
        OauthAppStatus::PUBLISHED->value => [
            'time_unused_until_expired' => 6,
            'unit_unused_until_expired' => 'months',
        ],
        OauthAppStatus::TESTING->value => [
            'time_unused_until_expired' => 7,
            'unit_unused_until_expired' => 'days',
        ],
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

    'settings_required' => env('YOUTUBE_SETTINGS_REQUIRED', true),

    'settings' => [
        'YOUTUBE_PROJECT_ID' => [
            'name'        => 'YOUTUBE_PROJECT_ID',
            'placeholder' => 'Enter your YouTube Project ID here without any space',
            'description' => 'YouTube Project ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'YOUTUBE_CLIENT_ID' => [
            'name'        => 'YOUTUBE_CLIENT_ID',
            'placeholder' => 'Enter your YouTube Client ID here without any space',
            'description' => 'YouTube Client ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'YOUTUBE_CLIENT_SECRET' => [
            'name'        => 'YOUTUBE_CLIENT_SECRET',
            'placeholder' => 'Enter your YouTube Client Secret without any space',
            'description' => 'YouTube Client Secret',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'OAUTH_APP_STATUS' => [
            'name'        => 'OAUTH_APP_STATUS',
            'placeholder' => 'Enter the status of the OAuth app',
            'description' => 'OAuth App status',
            'type'        => 'select',
            'values'      => [OauthAppStatus::PUBLISHED->value, OauthAppStatus::TESTING->value],
            'rules'       => 'required|in:' . implode(',', [OauthAppStatus::PUBLISHED->value, OauthAppStatus::TESTING->value]),
        ],
    ],
];
