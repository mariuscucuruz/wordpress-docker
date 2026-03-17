<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;

return [
    'name'         => 'acrcloud',
    'key'          => 'ACRCLOUD',
    'description'  => 'Audio recognition service provider for music recognition and copyright compliance.',
    'logo'         => 'acrcloud.png',
    'active'       => env('ACRCLOUD_ACTIVE', true),
    'type'         => PackageTypes::FUNCTION->value,
    'instructions' => 'readme.md',

    'region'        => env('ACR_REGION', 'eu-west-1'),
    'bearer_token'  => env('ACR_BEARER_TOKEN'),
    'signing_token' => env('ACR_SIGN_TOKEN', hash('sha256', config('app.key') . ':acrcloud-webhook')),
    'callback_url'  => env('ACR_WEBHOOK_URL', url(path: 'webhooks/acrcloud', secure: true)),

    'acr_base_url' => 'https://api-v2.acrcloud.com/api',

    'settings' => [
        'ACR_REGION' => [
            'name'        => 'ACR_REGION',
            'placeholder' => 'Enter your ACR Region here without any space',
            'description' => 'ACR Region',
            'type'        => 'text',
        ],
        'ACR_BEARER_TOKEN' => [
            'name'        => 'ACR_BEARER_TOKEN',
            'placeholder' => 'Enter your ACR Bearer Token here without any space',
            'description' => 'ACR Bearer Token',
            'type'        => 'text',
        ],
    ],
];
