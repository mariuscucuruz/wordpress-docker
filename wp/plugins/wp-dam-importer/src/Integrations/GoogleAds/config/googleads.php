<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\OauthAppStatus;
use MariusCucuruz\DAMImporter\Enums\SettingsRequired;

return [
    'name'         => 'googleads',
    'description'  => 'Access Google Ads assets, creatives and campaigns via OAuth2.',
    'logo'         => 'googleads.png',
    'active'       => env('GOOGLEADS_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'docs/readme.md',

    'client_id'           => env('GOOGLEADS_CLIENT_ID'),
    'client_secret'       => env('GOOGLEADS_CLIENT_SECRET'),
    'manager_customer_id' => env('GOOGLEADS_MANAGER_CUSTOMER_ID'),
    'developer_token'     => env('GOOGLEADS_DEVELOPER_TOKEN'),
    'redirect_uri'        => env('GOOGLEADS_REDIRECT_URI')
        ? url(path: env('GOOGLEADS_REDIRECT_URI'), secure: true)
        : url(path: 'oauth2.medialakeapp.com/googleads-redirect', secure: true),
    'auth_uri'                    => 'https://accounts.google.com/o/oauth2/v2/auth',
    'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
    'token_uri'                   => 'https://oauth2.googleapis.com/token',
    'scope'                       => [
        'https://www.googleapis.com/auth/adwords',
        'openid',
        'email',
        'profile',
    ],
    'javascript_origins' => [env('APP_URL')],
    'access_type'        => 'offline',
    'approval_prompt'    => 'force',
    'prompt'             => 'select_account consent',

    'oauth_app_status' => env('GOOGLEADS_OAUTH_APP_STATUS') ?? OauthAppStatus::PUBLISHED->value,

    'refresh_token_expiration' => [
        OauthAppStatus::PUBLISHED->value => [
            'time_unused_until_expired' => 6,
            'unit_unused_until_expired' => 'months',
        ],
        OauthAppStatus::TESTING->value => [
            'time_unused_until_expired' => 7,
            'unit_unused_until_expired' => 'days',
        ],
    ],

    'settings_required' => env('GOOGLEADS_SETTINGS_REQUIRED', SettingsRequired::OAUTH->value),

    'settings' => [
        'GOOGLEADS_CLIENT_ID' => [
            'name'        => 'GOOGLEADS_CLIENT_ID',
            'description' => 'Google API Client ID',
            'placeholder' => '•••••.apps.googleusercontent.com',
            'type'        => 'text',
        ],
        'GOOGLEADS_CLIENT_SECRET' => [
            'name'        => 'GOOGLEADS_CLIENT_SECRET',
            'description' => 'Google API Client Secret',
            'placeholder' => '•••••••••••••••••••••••',
            'type'        => 'password',
        ],
        'GOOGLEADS_DEVELOPER_TOKEN' => [
            'name'        => 'GOOGLEADS_DEVELOPER_TOKEN',
            'description' => 'Google Ads Manager Developer Token',
            'placeholder' => '•••••••••••••••••••••••',
            'type'        => 'password',
        ],
    ],
];
