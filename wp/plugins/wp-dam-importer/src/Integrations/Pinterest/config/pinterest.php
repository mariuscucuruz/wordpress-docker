<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

/**
 * @note https://developers.pinterest.com/docs/getting-started/set-up-authentication-and-authorization/#authorization-code-grant
 * @note https://developers.pinterest.com/docs/api/v5/
 * @note https://developers.pinterest.com/docs/reference/rate-limits/
 */

return [
    'name'         => 'pinterest',
    'description'  => 'A social network and visual discovery engine for finding ideas of all kinds.',
    'logo'         => 'pinterest.svg',
    'active'       => env('PINTEREST_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'docs/readme.md',
    'clientId'     => env('PINTEREST_CLIENT_ID'),
    'clientSecret' => env('PINTEREST_CLIENT_SECRET'),
    'advertiserId' => env('PINTEREST_ADVERTISER_ID'),
    'businessId'   => env('PINTEREST_BUSINESS_ID'),
    'client_scope' => env('PINTEREST_CLIENT_SCOPE', 'user_accounts,biz_access,ads,pins:read,boards:read,pins:read_secret,boards:read_secret'),
    'redirect_uri' => env('PINTEREST_REDIRECT')
        ? url(path: env('PINTEREST_REDIRECT'), secure: true)
        : url(path: env('APP_URL') . '/pinterest-redirect', secure: true),

    'limit_per_request' => 50,

    'rate_limit' => env('PINTEREST_RATE_LIMIT', 1000),

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_DAY,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings_required' => env('PINTEREST_SETTINGS_REQUIRED', true),

    'settings' => [
        'PINTEREST_CLIENT_ID' => [
            'name'        => 'PINTEREST_CLIENT_ID',
            'placeholder' => 'Enter your Pinterest Client ID here without any space',
            'description' => 'Pinterest Client ID',
            'required'    => true,
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'PINTEREST_CLIENT_SECRET' => [
            'name'        => 'PINTEREST_CLIENT_SECRET',
            'placeholder' => 'Enter your Pinterest Secret here without any space',
            'description' => 'Pinterest Client Secret',
            'required'    => true,
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
