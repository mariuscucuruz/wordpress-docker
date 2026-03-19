<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'onedrive',
    'description'  => "Microsoft's cloud storage and file hosting service.",
    'logo'         => 'ondedrive.svg',
    'active'       => env('ONEDRIVE_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'client_id'     => env('ONEDRIVE_CLIENT_ID'),
    'client_secret' => env('ONEDRIVE_CLIENT_SECRET'),
    'tenant_id'     => env('ONEDRIVE_TENANT_ID'),
    'redirect_uri'  => env('ONEDRIVE_REDIRECT')
        ? url(path: env('ONEDRIVE_REDIRECT'), secure: true)
        : url(path: env('APP_URL') . '/onedrive-redirect', secure: true),
    'oauth_base_url' => 'https://login.microsoftonline.com',
    'query_base_url' => 'https://graph.microsoft.com/v1.0',
    'scope'          => 'offline_access https://graph.microsoft.com/.default',
    'token_url'      => 'https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token',

    'limit_per_request' => 200,

    // https://learn.microsoft.com/en-us/graph/throttling-limits
    // 15,0000 request per 3,600 seconds, per app per tenant
    'rate_limit' => env('ONEDRIVE_LIMIT', 15000),

    'settings_required' => env('ONEDRIVE_SETTINGS_REQUIRED', true),

    'metadata_fields' => [
        'webUrl'                       => 'source_url',
        '@microsoft.graph.downloadUrl' => 'download_url',
    ],

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings' => [
        'ONEDRIVE_CLIENT_ID' => [
            'name'        => 'ONEDRIVE_CLIENT_ID',
            'placeholder' => 'Enter your Onedrive Client ID here without any space',
            'description' => 'Onedrive Client ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'ONEDRIVE_SECRET' => [
            'name'        => 'ONEDRIVE_SECRET',
            'placeholder' => 'Enter your Onedrive Secret here without any space',
            'description' => 'Onedrive Client Secret',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'ONEDRIVE_TENANT_ID' => [
            'name'        => 'ONEDRIVE_TENANT_ID',
            'placeholder' => 'Enter your Onedrive Tenant ID here without any space',
            'description' => 'Onedrive Tenant ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
