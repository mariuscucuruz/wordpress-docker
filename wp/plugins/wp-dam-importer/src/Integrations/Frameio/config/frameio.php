<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;

return [
    'name'         => 'frameio',
    'description'  => 'A collaborative video review and approval platform for professionals.',
    'logo'         => 'frame-io.png',
    'active'       => env('FRAMEIO_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => '',

    'baseUrl'       => 'https://api.frame.io/v2',
    'client_id'     => env('FRAMEIO_CLIENT_ID'),
    'client_secret' => env('FRAMEIO_CLIENT_SECRET'),
    'redirect_uri'  => env('FRAMEIO_REDIRECT_URI')
        ? url(path: env('FRAMEIO_REDIRECT_URI'), secure: true)
        : url(path: env('APP_URL') . '/frameio-redirect', secure: true),
    'token'         => env('FRAMEIO_TOKEN'),
    'scope'         => env('FRAMEIO_SCOPE', 'offline account.read asset.read'),
    'token_url'     => env('FRAMEIO_TOKEN_URL', 'https://applications.frame.io/oauth2/token'),
    'authorize_url' => env('FRAMEIO_AUTHORIZE_URL', 'https://applications.frame.io/oauth2/auth'),

    'settings_required' => env('FRAMEIO_SETTINGS_REQUIRED', true),

    'settings' => [
        'FRAMEIO_CLIENT_ID' => [
            'name'        => 'FRAMEIO_CLIENT_ID',
            'placeholder' => 'Enter your Frame.io Client ID here without any space',
            'description' => 'Frame.io Client ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'FRAMEIO_CLIENT_SECRET' => [
            'name'        => 'FRAMEIO_CLIENT_SECRET',
            'placeholder' => 'Enter your Frame.io Client Secret here without any space',
            'description' => 'Frame.io Secret',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
