<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'egnyte',
    'description'  => 'The Intelligent Content Platform.',
    'logo'         => 'egnyte.png',
    'active'       => env('EGNYTE_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    $apiVersion = env('EGNYTE_API_VERSION', 'v1'),
    'query_base_suffix' => 'pubapi/' . $apiVersion,
    'oauth_base_suffix' => 'puboauth/token',
    'redirect_uri'      => env('EGNYTE_REDIRECT_URI')
        ? url(path: env('EGNYTE_REDIRECT_URI'), secure: true)
        : url(path: env('APP_URL') . '/egnyte-redirect', secure: true),

    'scope' => 'Egnyte.filesystem Egnyte.link Egnyte.user',
    'count' => 100,

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings_required' => env('EGNYTE_SETTINGS_REQUIRED', true),

    'settings' => [
        'EGNYTE_SERVER_SUBDOMAIN' => [
            'name'        => 'EGNYTE_SERVER_SUBDOMAIN',
            'placeholder' => 'Enter your Egnyte Server Subdomain URL here without any space.',
            'description' => 'Egnyte Server Subdomain',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'EGNYTE_CLIENT_ID' => [
            'name'        => 'EGNYTE_CLIENT_ID',
            'placeholder' => 'Enter your Egnyte Client ID here without any space.',
            'description' => 'Egnyte Client Id',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'EGNYTE_CLIENT_SECRET' => [
            'name'        => 'EGNYTE_CLIENT_SECRET',
            'placeholder' => 'Enter your Egnyte Client Secret here without any space.',
            'description' => 'Egnyte Client Secret',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
