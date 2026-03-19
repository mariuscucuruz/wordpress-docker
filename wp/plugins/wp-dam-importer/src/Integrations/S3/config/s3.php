<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'                  => 's3',
    'description'           => 'Simple Storage Service is a scalable, secure, and highly durable object storage service provided by AWS.',
    'logo'                  => 'amazons3.svg',
    'active'                => env('S3_ACTIVE', true),
    'type'                  => PackageTypes::SOURCE->value,
    'quickstart'            => 'quickstart.md',
    'instructions'          => 'readme.md',
    's3_client_version'     => 'latest',
    'iam_client_version'    => 'latest',
    'destination_s3_bucket' => env('AWS_BUCKET'),

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_TWELVE_HOURS,
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'per_page' => 1000,

    'settings_required' => env('S3_SETTINGS_REQUIRED', true),

    'aws_regions' => [
        'us-east-1',     // N. Virginia
        'us-east-2',     // Ohio
        'us-west-1',     // N. California
        'us-west-2',     // Oregon
        'ap-south-1',    // Mumbai
        'ap-northeast-1', // Tokyo
        'ap-northeast-2', // Seoul
        'ap-northeast-3', // Osaka
        'ap-southeast-1', // Singapore
        'ap-southeast-2', // Sydney
        'ca-central-1',   // Canada Central
        'eu-central-1',   // Frankfurt
        'eu-west-1',      // Ireland
        'eu-west-2',      // London
        'eu-west-3',      // Paris
        'eu-north-1',     // Stockholm
        'sa-east-1',      // São Paulo
    ],

    'settings' => [
        'S3_ACCESS_KEY' => [
            'name'        => 'S3_ACCESS_KEY',
            'placeholder' => 'Enter your S3 Access Key here without any space',
            'description' => 'S3 Access Key',
            'type'        => 'text',
        ],
        'S3_SECRET_ACCESS_KEY' => [
            'name'        => 'S3_SECRET_ACCESS_KEY',
            'placeholder' => 'Enter your S3 Secret Access Key here without any space',
            'description' => 'S3 Secret Access',
            'type'        => 'text',
        ],
        'S3_REGION' => [
            'name'        => 'S3_REGION',
            'placeholder' => 'Enter your S3 Region here without any space',
            'description' => 'S3 Region',
            'type'        => 'text', // Todo: make this a select list with aws_regions
        ],
        'S3_BUCKET_NAME' => [
            'name'        => 'S3_BUCKET_NAME',
            'placeholder' => 'Enter your S3 Bucket Name here without any space',
            'description' => 'S3 Bucket Name',
            'type'        => 'text',
        ],
    ],
];
