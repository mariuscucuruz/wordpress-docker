<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'acquia',
    'description'  => 'Versatile cloud storage and digital asset management platform.',
    'logo'         => 'acquia.svg',
    'active'       => env('ACQUIA_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'bearer_token'   => env('ACQUIA_BEARER_TOKEN'),
    'query_base_url' => 'https://api.widencollective.com/v2',

    'per_page' => 100,

    'settings_required' => env('ACQUIA_SETTINGS_REQUIRED', true),

    'settings' => [
        'ACQUIA_DOMAIN_URL' => [
            'name'        => 'ACQUIA_DOMAIN_URL', // Needed to create asset source link
            'placeholder' => 'Enter your Acquia Domain URL here without any space',
            'description' => 'Acquia Domain',
            'type'        => 'text',
            'rules'       => 'required|url:http,https',
        ],
        'ACQUIA_BEARER_TOKEN' => [
            'name'        => 'ACQUIA_BEARER_TOKEN',
            'placeholder' => 'Enter your Acquia Bearer Token here without any space',
            'description' => 'Acquia Bearer Token',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],

];
