<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;
use MariusCucuruz\DAMImporter\Enums\SettingsRequired;

return [
    'name'         => 'Frontify',
    'description'  => 'A brand-building platform that serves as a centralized hub for brand management',
    'active'       => env('FRONTIFY_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'docs/readme.md',

    'redirect_uri' => env('FRONTIFY_REDIRECT', url(path: '/frontify-redirect', secure: true)),
    'logo'         => 'frontify.png',

    'pagination_limit' => env('FRONTIFY_PAGE_LIMIT', 100),
    'pagination_start' => env('FRONTIFY_PAGE_START', 1),

    'settings_required' => env('FRONTIFY_SETTINGS_REQUIRED', true),

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
        ],
    ],

    'api_setting_keys' => [
        SettingsRequired::OAUTH->value => 'FRONTIFY_OAUTH2_SETTINGS',
        SettingsRequired::TOKEN->value => 'FRONTIFY_BEARER_SETTINGS',
    ],

    'settings' => [
        'FRONTIFY_ACCOUNT_TYPE_SETTINGS' => [
            'name'        => 'FRONTIFY_ACCOUNT_TYPE_SETTINGS',
            'children'    => ['FRONTIFY_OAUTH2_SETTINGS', 'FRONTIFY_BEARER_SETTINGS'],
            'type'        => 'radio',
            'description' => 'Credentials Type',
            'placeholder' => 'Select the type of Frontify credentials you are using',
            'values'      => [
                //                [
                //                    'value'       => 'FRONTIFY_OAUTH2_SETTINGS',
                //                    'label'       => 'Frontify OAuth2',
                //                    'placeholder' => 'Frontify OAuth2',
                //                ],
                [
                    'value'       => 'FRONTIFY_BEARER_SETTINGS',
                    'label'       => 'Frontify Bearer Token',
                    'placeholder' => 'Frontify Bearer Token',
                ],
            ],
            'rules' => 'required|in:FRONTIFY_OAUTH2_SETTINGS,FRONTIFY_BEARER_SETTINGS',
        ],

        //        'FRONTIFY_OAUTH2_SETTINGS' => [
        //            'FRONTIFY_CLIENT_ID' => [
        //                'name'        => 'FRONTIFY_CLIENT_ID',
        //                'placeholder' => 'Frontify Client ID',
        //                'description' => 'Frontify Client ID',
        //                'type'        => 'text',
        //                'rules'       => 'required_if:FRONTIFY_ACCOUNT_TYPE_SETTINGS,FRONTIFY_OAUTH2_SETTINGS|string',
        //            ],
        //            'FRONTIFY_CLIENT_SECRET' => [
        //                'name'        => 'FRONTIFY_CLIENT_SECRET',
        //                'placeholder' => 'Frontify Client Secret',
        //                'description' => 'Frontify Client Secret',
        //                'type'        => 'text',
        //                'rules'       => 'required_if:FRONTIFY_ACCOUNT_TYPE_SETTINGS,FRONTIFY_OAUTH2_SETTINGS|string',
        //            ],
        //            'FRONTIFY_TENANT' => [
        //                'name'        => 'FRONTIFY_TENANT',
        //                'description' => 'Frontify Tenant',
        //                'placeholder' => 'Tenant e.g "brand.medialake.com"',
        //                'type'        => 'text',
        //                'rules'       => 'required_if:FRONTIFY_ACCOUNT_TYPE_SETTINGS,FRONTIFY_OAUTH2_SETTINGS|string',
        //            ],
        //            'FRONTIFY_ACCOUNT_TYPE' => [
        //                'name'        => 'FRONTIFY_ACCOUNT_TYPE',
        //                'placeholder' => 'Frontify Account Type',
        //                'description' => 'Frontify Account Type',
        //                'type'        => 'select',
        //                'values'      => [SettingsRequired::OAUTH->value],
        //                'rules'       => 'required_if:FRONTIFY_ACCOUNT_TYPE_SETTINGS,FRONTIFY_OAUTH2_SETTINGS|in:OAUTH,TOKEN',
        //            ],
        //        ],

        'FRONTIFY_BEARER_SETTINGS' => [
            'FRONTIFY_DEVELOPER_TOKEN' => [
                'name'        => 'FRONTIFY_DEVELOPER_TOKEN',
                'description' => 'Frontify Developer Token',
                'placeholder' => 'Frontify Developer Token',
                'type'        => 'text',
            ],
            'FRONTIFY_TENANT' => [
                'name'        => 'FRONTIFY_TENANT',
                'description' => 'Frontify Tenant',
                'placeholder' => 'Tenant e.g "brand.medialake.com"',
                'type'        => 'text',
            ],
            'FRONTIFY_ACCOUNT_TYPE' => [
                'name'        => 'FRONTIFY_ACCOUNT_TYPE',
                'placeholder' => 'Frontify Account Type',
                'description' => 'Frontify Account Type',
                'type'        => 'select',
                'values'      => [SettingsRequired::TOKEN->value],
                'rules'       => 'required_if:FRONTIFY_ACCOUNT_TYPE_SETTINGS,FRONTIFY_OAUTH2_SETTINGS|in:OAUTH,TOKEN',
            ],
        ],
    ],
];
