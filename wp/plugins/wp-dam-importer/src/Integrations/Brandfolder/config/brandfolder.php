<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'brandfolder',
    'description'  => 'Streamlined brand asset organisation and sharing: centralize, scale, manage, and distribute all your assets.',
    'logo'         => 'brandfolder.svg',
    'instructions' => 'docs/readme.md',
    'type'         => PackageTypes::SOURCE->value,
    'active'       => env('BRANDFOLDER_ACTIVE', true),
    'api_version'  => 'v4',
    'api_url'      => 'https://brandfolder.com/api',
    'redirect_uri' => env('BRANDFOLDER_REDIRECT', url(path: '/brandfolder-redirect', secure: true)),

    'pagination_start' => 1,
    'page_size'        => 100,

    'settings_required' => env('BRANDFOLDER_SETTINGS_REQUIRED', true),

    'settings' => [
        'BRANDFOLDER_API_KEY' => [
            'name'        => 'BRANDFOLDER_API_KEY',
            'description' => 'Brandfolder API key (no spaces)',
            'placeholder' => 'Brandfolder API Key',
            'type'        => 'text',
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
];
