<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'peach',
    'description'  => 'An ad content management and delivery platform.',
    'logo'         => 'peach.png',
    'active'       => env('PEACH_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'client_id'     => env('PEACH_CLIENT_ID'),
    'client_secret' => env('PEACH_CLIENT_SECRET'),
    'redirect_uri'  => env('PEACH_REDIRECT_URI')
        ? url(path: env('PEACH_REDIRECT_URI'), secure: true)
        : url(path: env('APP_URL') . '/peach-redirect', secure: true),
    'oauth_base_url' => 'https://auth.api.peach.me/oauth2',
    'query_base_url' => 'https://api.peach.me/v1/content/input',
    'scope'          => 'peach/campaign.read peach/user.read peach/asset.read peach/ad.read peach/account.read',
    'token_url'      => 'https://auth.api.peach.me/oauth2/token',

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings_required' => env('PEACH_SETTINGS_REQUIRED', true),

    'settings' => [
        'PEACH_CLIENT_ID' => [
            'name'        => 'PEACH_CLIENT_ID',
            'placeholder' => 'Enter your Peach Client ID here without any space',
            'description' => 'Peach Client ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'PEACH_CLIENT_SECRET' => [
            'name'        => 'PEACH_CLIENT_SECRET',
            'placeholder' => 'Enter your Peach Client Secret here without any space',
            'description' => 'Peach Client Secret',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
