<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;
use MariusCucuruz\DAMImporter\Enums\SettingsRequired;

return [
    'name'         => 'bynder',
    'description'  => 'A digital asset management platform.',
    'logo'         => 'bynder.svg',
    'active'       => env('BYNDER_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'client_id'     => env('BYNDER_CLIENT_ID'),
    'client_secret' => env('BYNDER_CLIENT_SECRET'),
    'domain_url'    => env('BYNDER_DOMAIN_URL'),

    'limit'      => 100,
    'page_start' => 1,

    'auth_token_path' => 'authentication/oauth2/token',

    'authorize_api_version' => 'v6',
    'query_api_version'     => 'v4',

    'settings_required' => env('BYNDER_SETTINGS_REQUIRED', true),

    'rate_limit' => 4500, // https://bynder.docs.apiary.io/#introduction/changelog/2024 4500 every 5 minutes

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'api_setting_keys' => [
        SettingsRequired::OAUTH->value => 'BYNDER_OAUTH2_SETTINGS',
        SettingsRequired::TOKEN->value => 'BYNDER_BEARER_SETTINGS',
    ],

    'settings' => [
        'BYNDER_ACCOUNT_TYPE_SETTINGS' => [
            'name'        => 'BYNDER_ACCOUNT_TYPE_SETTINGS',
            'children'    => ['BYNDER_OAUTH2_SETTINGS', 'BYNDER_BEARER_SETTINGS'],
            'type'        => 'radio',
            'description' => 'Bynder Credentials Type',
            'placeholder' => 'Select the type of Bynder credentials you are using',
            'values'      => [
                [
                    'value'       => 'BYNDER_OAUTH2_SETTINGS',
                    'label'       => 'Bynder OAuth2',
                    'placeholder' => 'Bynder OAuth2',
                ],
                [
                    'value'       => 'BYNDER_BEARER_SETTINGS',
                    'label'       => 'Bynder Bearer Token',
                    'placeholder' => 'Bynder Bearer Token',
                ],
            ],
            'rules' => 'required|in:BYNDER_OAUTH2_SETTINGS,BYNDER_BEARER_SETTINGS',
        ],

        'BYNDER_OAUTH2_SETTINGS' => [
            'BYNDER_CLIENT_ID' => [
                'name'        => 'BYNDER_CLIENT_ID',
                'placeholder' => 'Enter your Bynder Client ID here without any space',
                'description' => 'Bynder Client ID',
                'type'        => 'text',
                'rules'       => 'required_if:BYNDER_ACCOUNT_TYPE_SETTINGS,BYNDER_OAUTH2_SETTINGS|string',
            ],
            'BYNDER_CLIENT_SECRET' => [
                'name'        => 'BYNDER_CLIENT_SECRET',
                'placeholder' => 'Enter your Bynder Secret here without any space',
                'description' => 'Bynder Client Secret',
                'type'        => 'text',
                'rules'       => 'required_if:BYNDER_ACCOUNT_TYPE_SETTINGS,BYNDER_OAUTH2_SETTINGS|string',
            ],
            'BYNDER_DOMAIN_URL' => [
                'name'        => 'BYNDER_DOMAIN_URL',
                'placeholder' => 'Enter your Bynder Domain Url here without any space',
                'description' => 'Bynder Domain Url',
                'type'        => 'text',
                'rules'       => 'required_if:BYNDER_ACCOUNT_TYPE_SETTINGS,BYNDER_OAUTH2_SETTINGS|string',
            ],
            'BYNDER_ACCOUNT_TYPE' => [
                'name'        => 'BYNDER_ACCOUNT_TYPE',
                'placeholder' => 'Bynder Account Type',
                'description' => 'Bynder Account Type',
                'type'        => 'select',
                'values'      => [SettingsRequired::OAUTH->value],
                'rules'       => 'required_if:BYNDER_ACCOUNT_TYPE_SETTINGS,BYNDER_OAUTH2_SETTINGS|in:OAUTH,TOKEN',
            ],
        ],

        'BYNDER_BEARER_SETTINGS' => [
            'BYNDER_BEARER_TOKEN' => [
                'name'        => 'BYNDER_BEARER_TOKEN',
                'placeholder' => 'Enter your Bynder Bearer Token here without any space',
                'description' => 'Bynder Bearer Token',
                'type'        => 'text',
            ],
            'BYNDER_DOMAIN_URL' => [
                'name'        => 'BYNDER_DOMAIN_URL',
                'placeholder' => 'Enter your Bynder Domain Url here without any space',
                'description' => 'Bynder Domain Url',
                'type'        => 'text',
            ],

            'BYNDER_ACCOUNT_TYPE' => [
                'name'        => 'BYNDER_ACCOUNT_TYPE',
                'placeholder' => 'Bynder Account Type',
                'description' => 'Bynder Account Type',
                'type'        => 'select',
                'values'      => [SettingsRequired::TOKEN->value],
                'rules'       => 'required_if:BYNDER_ACCOUNT_TYPE_SETTINGS,BYNDER_OAUTH2_SETTINGS|in:OAUTH,TOKEN',
            ],
        ],
    ],
];
