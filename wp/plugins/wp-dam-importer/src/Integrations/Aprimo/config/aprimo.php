<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'Aprimo',
    'description'  => 'Aprimo DAM centralizes, organizes, and distributes digital assets efficiently.',
    'logo'         => 'aprimo.png',
    'active'       => env('APRIMO_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'docs/readme.md',

    'client_id'     => env('APRIMO_CLIENT_ID'),
    'client_secret' => env('APRIMO_CLIENT_SECRET'),
    'redirect_uri'  => env('APRIMO_REDIRECT', url(path: '/aprimo-redirect', secure: true)),

    'user_agent' => 'Medialake Aprimo API Client/1.0',

    'settings_required' => env('APRIMO_SETTINGS_REQUIRED', true),

    'api_version' => '1',

    'page_start' => 1,

    'page_size'      => 200,
    'api_batch_size' => env('APRIMO_API_BATCH_SIZE', 25),

    'rate_limit' => 15, // 15 per second https://developers.aprimo.com/docs/rate-limiting

    'ecco_custom_filter_flag' => env('APRIMO_ECCO_CUSTOM_FILTERS', true),

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings' => [
        'APRIMO_CLIENT_ID' => [
            'name'        => 'APRIMO_CLIENT_ID',
            'description' => 'Aprimo Client ID',
            'placeholder' => 'Aprimo Client ID',
            'type'        => 'text',
        ],
        'APRIMO_CLIENT_SECRET' => [
            'name'        => 'APRIMO_CLIENT_SECRET',
            'description' => 'Aprimo Client Secret',
            'placeholder' => 'Aprimo Secret',
            'type'        => 'text',
        ],
        'APRIMO_TENANT' => [
            'name'        => 'APRIMO_TENANT',
            'description' => 'Aprimo Tenant',
            'placeholder' => 'Tenant e.g "medialake"',
            'type'        => 'text',
        ],
    ],

    'default_folders' => [
        'allAssets' => [
            'id'                => 'allAssets',
            'name'              => 'All Assets (Images & Videos)',
            'search_expression' => "FileCount > 0 and (contentType = 'Video' or contentType = 'Image')",
            'default'           => true,
        ],
        'allRecords' => [
            'id'                => 'allRecords',
            'name'              => 'All Records',
            'search_expression' => 'FileCount > 0',
            'default'           => true,
        ],
    ],
];
