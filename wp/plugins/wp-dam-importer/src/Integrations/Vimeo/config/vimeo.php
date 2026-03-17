<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'vimeo',
    'description'  => 'A high-definition video sharing platform for creators.',
    'logo'         => 'vimeo.png',
    'active'       => env('VIMEO_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'client_id'     => env('VIMEO_CLIENT_ID'),
    'client_secret' => env('VIMEO_CLIENT_SECRET'),
    'redirect_uri'  => env('VIMEO_REDIRECT_URI')
        ? url(path: env('VIMEO_REDIRECT_URI'), secure: true)
        : url(path: env('APP_URL') . '/vimeo-redirect', secure: true),

    'authorizeUrl'   => 'https://api.vimeo.com/oauth/authorize',
    'accessTokenUrl' => 'https://api.vimeo.com/oauth/access_token',
    'scope'          => 'private purchased interact upload stats video_files scim public',

    'settings_required' => env('VIMEO_SETTINGS_REQUIRED', true),

    'per_page' => 100,

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
        'VIMEO_CLIENT_ID' => [
            'name'        => 'VIMEO_CLIENT_ID',
            'description' => 'Enter your Vimeo Client ID here without any space',
            'placeholder' => 'Vimeo Client ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'VIMEO_CLIENT_SECRET' => [
            'name'        => 'VIMEO_CLIENT_SECRET',
            'description' => 'Enter your Vimeo Client Secret without any space',
            'placeholder' => 'Vimeo Client Secret',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
