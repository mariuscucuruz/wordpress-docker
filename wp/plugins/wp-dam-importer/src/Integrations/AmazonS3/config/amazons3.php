<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'amazons3', // DO NOT CHANGE THIS! must be exactly the same as the package name
    'description'  => 'Object storage web service. Industry-leading in scalability, availability, security and performance.',
    'logo'         => 'amazons3.png',
    'active'       => env('S3_ACTIVE', true),
    'type'         => PackageTypes::STORAGE->value,
    'instructions' => 'readme.md',

    'key'    => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings_required' => env('AMAZONS3_SETTINGS_REQUIRED', true),

    'settings' => [
        'S3_ACCESS_KEY' => [
            'name'        => 'S3_ACCESS_KEY',
            'placeholder' => 'Enter your S3 Access Key here',
            'description' => 'S3 Access Key',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'S3_SECRET_KEY' => [
            'name'        => 'S3_SECRET_KEY',
            'placeholder' => 'Enter your S3 Secret Key here',
            'description' => 'S3 Secret Key',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'S3_REGION' => [
            'name'        => 'S3_REGION',
            'placeholder' => 'Enter your S3 Region here',
            'description' => 'S3 Region',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'S3_BUCKET' => [
            'name'        => 'S3_BUCKET',
            'placeholder' => 'Enter your S3 Bucket here',
            'description' => 'S3 Bucket',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
