<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [

    'name'         => 'dropbox',
    'description'  => 'A cloud storage and file-sharing platform.',
    'logo'         => 'dropbox.png',
    'active'       => env('DROPBOX_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'per_page' => 100,

    'client_id'     => env('DROPBOX_CLIENT_ID'),
    'client_secret' => env('DROPBOX_SECRET_ID'),
    'redirect_uri'  => env('DROPBOX_REDIRECT_URI')
        ? url(path: env('DROPBOX_REDIRECT_URI'), secure: true)
        : url(path: env('APP_URL') . '/dropbox-redirect', secure: true),
    'access_type'  => 'offline',
    'scopes'       => 'account_info.read files.metadata.write files.metadata.read files.content.read file_requests.read',
    'download_url' => 'https://content.dropboxapi.com/2/files/download',

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings_required' => env('DROPBOX_SETTINGS_REQUIRED', true),

    'settings' => [
        'DROPBOX_CLIENT_ID' => [
            'name'        => 'DROPBOX_CLIENT_ID',
            'placeholder' => 'Enter your Dropbox Client ID here without any space',
            'description' => 'Dropbox Client ID',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'DROPBOX_SECRET_ID' => [
            'name'        => 'DROPBOX_SECRET_ID',
            'placeholder' => 'Enter your Dropbox Secret ID here without any space',
            'description' => 'Dropbox Client Secret',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
    ],
];
