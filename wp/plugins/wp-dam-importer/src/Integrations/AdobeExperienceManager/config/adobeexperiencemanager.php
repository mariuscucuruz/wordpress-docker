<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'adobeexperiencemanager',
    'description'  => 'A digital asset and content management system.',
    'active'       => env('ADOBE_EXPERIENCE_MANAGER_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',
    'logo'         => 'adobeexperiencemanager.svg',

    'query_base_url'       => env('ADOBE_EXPERIENCE_MANAGER_QUERY_BASE_URL'),
    'client_id'            => env('ADOBE_EXPERIENCE_MANAGER_CLIENT_ID'),
    'client_secret'        => env('ADOBE_EXPERIENCE_MANAGER_CLIENT_SECRET'),
    'technical_account_id' => env('ADOBE_EXPERIENCE_MANAGER_TECHNICAL_ACCOUNT_ID'),
    'org_id'               => env('ADOBE_EXPERIENCE_MANAGER_ORG_ID'),
    'private_key'          => env('ADOBE_EXPERIENCE_MANAGER_PRIVATE_KEY'),
    'metascopes'           => env('ADOBE_EXPERIENCE_MANAGER_METASCOPES'),
    'ims'                  => env('ADOBE_EXPERIENCE_MANAGER_IMS'),

    // temporary
    'last_replication_action_publish' => env('ADOBE_EXPERIENCE_MANAGER_LAST_REPLICATION_ACTION_PUBLISH', 'Activate'),

    'root_branch' => '/api/assets',

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'limit'                  => 200,
    'metadata_refresh_limit' => 50,

    'settings_required' => env('ADOBE_EXPERIENCE_MANAGER_SETTINGS_REQUIRED', true),

    'redirect_url' => env('ADOBE_EXPERIENCE_MANAGER_REDIRECT_URL'),

    'delta_sync_url' => 'bin/querybuilder.json',

    'settings' => [
        'ADOBE_EXPERIENCE_MANAGER_QUERY_BASE_URL' => [
            'name'        => 'ADOBE_EXPERIENCE_MANAGER_QUERY_BASE_URL',
            'placeholder' => 'Enter your AEM Query Base URL here without any space',
            'description' => 'AEM Query Base URL',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'SERVICE_CREDENTIALS_JSON_FILE' => [
            'name'        => 'SERVICE_CREDENTIALS_JSON_FILE',
            'placeholder' => 'Upload AEM Service Credentials JSON file here',
            'description' => 'Upload JSON File',
            'type'        => 'file',
            'accept'      => 'application/json',
            'rules'       => 'required|file|mimes:json|max:2048',
        ],
        'clientId' => [
            'name'        => 'ADOBE_EXPERIENCE_MANAGER_REDIRECT_URL',
            'description' => 'AEM Redirect Url',
            'placeholder' => 'AEM Redirect Url',
            'hidden'      => true,
            'rules'       => 'required|string',
        ],
        'clientSecret' => [
            'name'        => 'ADOBE_EXPERIENCE_MANAGER_CLIENT_SECRET',
            'description' => 'AEM Client Secret',
            'placeholder' => 'AEM Client Secret',
            'hidden'      => true,
            'rules'       => 'required|string',
        ],
        'technicalAccountId' => [
            'name'        => 'ADOBE_EXPERIENCE_MANAGER_ID',
            'description' => 'AEM Id',
            'placeholder' => 'AEM Id',
            'hidden'      => true,
            'rules'       => 'required|string',
        ],
        'orgId' => [
            'name'        => 'ADOBE_EXPERIENCE_MANAGER_ORG',
            'description' => 'AEM Org',
            'placeholder' => 'AEM Org',
            'hidden'      => true,
            'rules'       => 'required|string',
        ],
        'privateKey' => [
            'name'        => 'ADOBE_EXPERIENCE_MANAGER_PRIVATE_KEY',
            'description' => 'AEM Private Key',
            'placeholder' => 'AEM Private Key',
            'hidden'      => true,
            'rules'       => 'required|string',
        ],
        'metascopes' => [
            'name'        => 'ADOBE_EXPERIENCE_MANAGER_METASCOPES',
            'description' => 'AEM Private Metascopes',
            'placeholder' => 'AEM Private Metascopes',
            'hidden'      => true,
            'rules'       => 'required|string',
        ],
        'ims' => [
            'name'        => 'ADOBE_EXPERIENCE_MANAGER_IMS_ENDPOINT',
            'description' => 'AEM Ims Endpoint',
            'placeholder' => 'AEM Ims Endpoint',
            'hidden'      => true,
            'rules'       => 'required|string',
        ],
    ],
];
