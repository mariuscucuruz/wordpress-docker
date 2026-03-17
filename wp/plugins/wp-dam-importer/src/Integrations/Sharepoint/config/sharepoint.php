<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'sharepoint',
    'description'  => "Microsoft's document management and storage system.",
    'logo'         => 'sharepoint.png',
    'active'       => env('SHAREPOINT_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'client_id'     => env('SHAREPOINT_CLIENT_ID'),
    'client_secret' => env('SHAREPOINT_SECRET'),
    'tenant_id'     => env('SHAREPOINT_TENANT_ID'),
    'redirect_uri'  => env('SHAREPOINT_REDIRECT')
        ? url(path: env('SHAREPOINT_REDIRECT'), secure: true)
        : url(path: env('APP_URL') . '/sharepoint-redirect', secure: true),

    'oauth_base_url' => 'https://login.microsoftonline.com',
    'query_base_url' => 'https://graph.microsoft.com/v1.0',
    'scope'          => 'offline_access https://graph.microsoft.com/.default',
    'token_url'      => 'https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token',

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings_required' => env('SHAREPOINT_SETTINGS_REQUIRED', true),

    'settings' => [
        'SHAREPOINT_CLIENT_ID' => [
            'name'        => 'SHAREPOINT_CLIENT_ID',
            'placeholder' => 'Enter your Sharepoint Client ID here without any space',
            'description' => 'Sharepoint Client ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'SHAREPOINT_SECRET' => [
            'name'        => 'SHAREPOINT_SECRET',
            'placeholder' => 'Enter your Sharepoint Secret here without any space',
            'description' => 'Sharepoint Client Secret',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'SHAREPOINT_TENANT_ID' => [
            'name'        => 'SHAREPOINT_TENANT_ID',
            'placeholder' => 'Enter your Sharepoint Tenant ID here without any space',
            'description' => 'Sharepoint Tenant ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
