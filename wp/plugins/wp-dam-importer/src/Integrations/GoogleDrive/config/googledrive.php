<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\OauthAppStatus;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'googledrive',
    'description'  => 'A file storage and synchronization service developed by Google.',
    'logo'         => 'googledrive.png',
    'active'       => env('GOOGLEDRIVE_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'default_params' => [
        'pageSize'                  => 1000, // 1000 max. Known API issue only currently returns 100 (default)
        'fields'                    => '*',
        'includeItemsFromAllDrives' => true,
        'supportsAllDrives'         => true,
        'corpora'                   => 'allDrives',
    ],

    'project_id'    => env('GOOGLE_PROJECT_ID'),
    'api_key'       => env('GOOGLE_API_KEY'),
    'client_id'     => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri'  => env('GOOGLE_REDIRECT_URI')
        ? url(path: env('GOOGLE_REDIRECT_URI'), secure: true)
        : url(path: 'oauth2.medialakeapp.com/googledrive-redirect', secure: true),
    'auth_uri'                    => 'https://accounts.google.com/o/oauth2/auth',
    'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
    'javascript_origins'          => [env('APP_URL')],
    'access_type'                 => 'offline',
    'approval_prompt'             => 'force',
    'prompt'                      => 'select_account consent',

    'oauth_app_status' => env('GOOGLEDRIVE_OAUTH_APP_STATUS') ?? OauthAppStatus::PUBLISHED->value,

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
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings_required' => env('GOOGLEDRIVE_SETTINGS_REQUIRED', true),

    'settings' => [
        'GOOGLE_PROJECT_ID' => [
            'name'        => 'GOOGLE_PROJECT_ID',
            'placeholder' => 'medialake-project',
            'description' => 'Google Project ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'GOOGLE_CLIENT_ID' => [
            'name'        => 'GOOGLE_CLIENT_ID',
            'placeholder' => '•••••.apps.googleusercontent.com',
            'description' => 'Google Client ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'GOOGLE_CLIENT_SECRET' => [
            'name'        => 'GOOGLE_CLIENT_SECRET',
            'placeholder' => '•••••••••••••••••••••••',
            'description' => 'Google Client Secret',
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

    'mime_types' => [
        'document'     => 'application/vnd.google-apps.document',
        'presentation' => 'application/vnd.google-apps.presentation',
        'spreadsheet'  => 'application/vnd.google-apps.spreadsheet',
        'drawing'      => 'application/vnd.google-apps.drawing',
        'form'         => 'application/vnd.google-apps.form',
        'map'          => 'application/vnd.google-apps.map',
        'script'       => 'application/vnd.google-apps.script',
        'site'         => 'application/vnd.google-apps.site',
        'unknown'      => 'application/vnd.google-apps.unknown',
        'video'        => 'application/vnd.google-apps.video',
        'audio'        => 'application/vnd.google-apps.audio',
        'photo'        => 'application/vnd.google-apps.photo',
        'drive'        => 'application/vnd.google-apps.drive-sdk',
        'kix'          => 'application/vnd.google-apps.kix',
        'folder'       => 'application/vnd.google-apps.folder',
        'shortcut'     => 'application/vnd.google-apps.shortcut',
    ],
];
