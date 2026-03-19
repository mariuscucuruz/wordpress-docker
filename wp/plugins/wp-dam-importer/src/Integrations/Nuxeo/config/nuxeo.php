<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'nuxeo',
    'description'  => 'Content management platform.',
    'active'       => env('NUXEO_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',
    'logo'         => 'nuxeo.png',

    'username'       => env('NUXEO_USERNAME'),
    'password'       => env('NUXEO_PASSWORD'),
    'server'         => env('NUXEO_SERVER_SUBDOMAIN'),
    'query_base_url' => 'https://api.nuxeo.com/nuxeo/api/v1',

    'page_size' => 2000, // max 2000

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings_required' => env('NUXEO_SETTINGS_REQUIRED', true),

    'settings' => [
        'NUXEO_USERNAME' => [
            'name'        => 'NUXEO_USERNAME',
            'placeholder' => 'Enter your Nuxeo Username here without any space',
            'description' => 'Nuxeo Username',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'NUXEO_PASSWORD' => [
            'name'        => 'NUXEO_PASSWORD',
            'placeholder' => 'Enter your Nuxeo Password here without any space',
            'description' => 'Nuxeo Password',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'NUXEO_SERVER_SUBDOMAIN' => [
            'name'        => 'NUXEO_SERVER_SUBDOMAIN',
            'placeholder' => 'Enter your Nuxeo Server subdomain here without any space.', // Ex: For https://demo.nuxeo.com, please enter "demo".
            'description' => 'Nuxeo Server subdomain. Ex. demo',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
