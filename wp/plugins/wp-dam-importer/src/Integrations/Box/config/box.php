<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;

return [
    'name'         => 'Box',
    'description'  => 'Secure content management and collaboration platform.',
    'active'       => env('BOX_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'docs/readme.md',

    'client_id'     => env('BOX_CLIENT_ID'),
    'client_secret' => env('BOX_CLIENT_SECRET'),

    'redirect_uri' => env('BOX_REDIRECT')
        ? url(path: env('BOX_REDIRECT'), secure: true)
        : url(path: env('APP_URL') . '/box-redirect', secure: true),

    'oauth_base_url' => 'https://account.box.com/api',
    'query_base_url' => 'https://api.box.com',
    'logo'           => 'Box.png',

    'settings_required' => env('BOX_SETTINGS_REQUIRED', true),

    'settings' => [
        'BOX_CLIENT_ID' => [
            'name'        => 'BOX_CLIENT_ID',
            'placeholder' => 'Enter your Box Client ID here without any space',
            'description' => 'Box Client ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'BOX_CLIENT_SECRET' => [
            'name'        => 'BOX_CLIENT_SECRET',
            'placeholder' => 'Enter your Box Client Secret here without any space',
            'description' => 'Box Secret',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
