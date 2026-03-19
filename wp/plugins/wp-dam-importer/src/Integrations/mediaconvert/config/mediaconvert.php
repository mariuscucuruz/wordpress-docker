<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;

return [
    'name'         => 'mediaconvert',
    'key'          => 'MEDIACONVERT',
    'description'  => 'A versatile multimedia processing tool that enables users to convert, compress, and manipulate various audio, video, and image formats.',
    'logo'         => 'mediaconvert.png',
    'active'       => true,
    'type'         => PackageTypes::FUNCTION->value,
    'instructions' => 'readme.md',

    'directory' => 'mediaconvert',

    'binary' => env('MEDIACONVERT_BINARY', 'aws'),

    'credentials' => [
        'key'    => env('AWS_ID') ?? env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET') ?? env('AWS_SECRET_ACCESS_KEY'),
    ],

    'version'           => 'latest',
    'region'            => env('AWS_DEFAULT_REGION', 'eu-west-2'),
    'role'              => env('AWS_MEDIA_IAM_ROLE_ARN'),
    'endpoint'          => env('AWS_MEDIA_END_POINT'),
    'bucket'            => env('AWS_BUCKET', 'default'),
    'play_job_template' => env('PLAY_JOB_TEMPLATE', 'MEDIALAKE-PLAY-TEMPLATE'),

    'retry_on_failure'      => env('REKOGNITION_RETRY_ON_FAILURE', 3),
    'retry_in_milliseconds' => env('MEDIACONVERT_RETRY_IN_MILLISECONDS', 5000),

    'output_extensions' => [
        'image' => '.mp4',
        'video' => '.mp4',
        'audio' => '.mp3',
    ],

    'output_profile' => [
        'default' => 720,
    ],

    'settings' => [
        'AWS_ACCESS_KEY_ID' => [
            'name'        => 'AWS_ACCESS_KEY_ID',
            'description' => 'Enter your AWS Access Key ID here without any space',
            'placeholder' => 'AWS Access Key ID',
            'type'        => 'text',
        ],
        'AWS_SECRET_ACCESS_KEY' => [
            'name'        => 'AWS_SECRET_ACCESS_KEY',
            'description' => 'Enter your AWS Secret Access Key here without any space',
            'placeholder' => 'AWS Secret Access Key',
            'type'        => 'text',
        ],
        'AWS_DEFAULT_REGION' => [
            'name'        => 'AWS_DEFAULT_REGION',
            'description' => 'Enter your AWS Default Region here without any space',
            'placeholder' => 'AWS Default Region',
            'type'        => 'text',
        ],
        'AWS_BUCKET' => [
            'name'        => 'AWS_BUCKET',
            'description' => 'Enter your AWS Bucket here without any space',
            'placeholder' => 'AWS Bucket',
            'type'        => 'text',
        ],
        'AWS_MEDIA_IAM_ROLE_ARN' => [
            'name'        => 'AWS_MEDIA_IAM_ROLE_ARN',
            'description' => 'Enter your AWS Media IAM Role ARN here without any space',
            'placeholder' => 'AWS Media IAM Role ARN',
            'type'        => 'text',
        ],
        'AWS_MEDIA_END_POINT' => [
            'name'        => 'AWS_MEDIA_END_POINT',
            'description' => 'Enter your AWS Media End Point here without any space',
            'placeholder' => 'AWS Media End Point',
            'type'        => 'text',
        ],
    ],
];
