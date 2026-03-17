<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;
use MariusCucuruz\DAMImporter\Integrations\Instagram\Enums\InstagramServiceType;

return [
    'name'         => 'instagram',
    'description'  => 'A popular social media platform for sharing photos and videos.',
    'logo'         => 'instagram.png',
    'active'       => env('INSTAGRAM_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'settings_required' => env('INSTAGRAM_SETTINGS_REQUIRED', true),

    'metadata_fields' => [
        'media_url'      => 'source_link',
        'caption'        => 'caption',
        'permalink'      => 'view_link',
        'like_count'     => 'like_count',
        'comments_count' => 'comments_count',
    ],

    'api_setting_keys' => [
        // InstagramServiceType::PERSONAL->value => 'INSTAGRAM_BASIC_DISPLAY_SETTINGS',
        InstagramServiceType::BUSINESS->value => 'INSTAGRAM_GRAPH_SETTINGS',
    ],

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_DAY,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings' => [
        'INSTAGRAM_ACCOUNT_TYPE_SETTINGS' => [
            'name'        => 'INSTAGRAM_ACCOUNT_TYPE_SETTINGS',
            'children'    => ['INSTAGRAM_GRAPH_SETTINGS'], // ['INSTAGRAM_BASIC_DISPLAY_SETTINGS', 'INSTAGRAM_GRAPH_SETTINGS'],
            'type'        => 'radio',
            'description' => 'Instagram Account Type',
            'placeholder' => 'Select the type of Instagram account you are using',
            'values'      => [
                [
                    'value'       => 'INSTAGRAM_GRAPH_SETTINGS',
                    'label'       => 'Instagram Graph API',
                    'placeholder' => 'Typically for business accounts',
                    'description' => 'Typically for business accounts',
                ],
            ],
            'rules' => 'required|in:INSTAGRAM_GRAPH_SETTINGS',
        ],

        'INSTAGRAM_GRAPH_SETTINGS' => [
            'INSTAGRAM_GRAPH_CLIENT_ID' => [
                'name'        => 'INSTAGRAM_GRAPH_CLIENT_ID',
                'placeholder' => 'Enter your Instagram Client ID here without any space',
                'description' => 'Instagram Client ID',
                'type'        => 'text',
                'rules'       => 'required_if:INSTAGRAM_ACCOUNT_TYPE_SETTINGS,INSTAGRAM_GRAPH_SETTINGS|string',
            ],
            'INSTAGRAM_GRAPH_SECRET' => [
                'name'        => 'INSTAGRAM_GRAPH_SECRET',
                'placeholder' => 'Enter your Instagram Secret here without any space',
                'description' => 'Instagram Secret',
                'type'        => 'text',
                'rules'       => 'required_if:INSTAGRAM_ACCOUNT_TYPE_SETTINGS,INSTAGRAM_GRAPH_SETTINGS|string',
            ],
            'INSTAGRAM_GRAPH_CONFIG_ID' => [
                'name'        => 'INSTAGRAM_GRAPH_CONFIG_ID',
                'placeholder' => 'Enter your Instagram Config here without any space',
                'description' => 'Instagram Config',
                'type'        => 'text',
                'rules'       => 'required_if:INSTAGRAM_ACCOUNT_TYPE_SETTINGS,INSTAGRAM_GRAPH_SETTINGS|string',
            ],
        ],
    ],
];
