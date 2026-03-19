<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;
use MariusCucuruz\DAMImporter\Enums\SettingsRequired;

return [
    'name'         => 'sftp',
    'description'  => 'A network protocol for securely accessing, transferring and managing large files and sensitive data',
    'logo'         => 'server.svg',
    'active'       => env('SFTP_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'readme.md',

    'excluded_folders' => [
        '.',
        '..',
        'snap',
        'bin',
        'root',
        'boot',
        'dev',
        'etc',
        'lib',
        'lib64',
        'sys',
        'tmp',
        'var',
        'opt',
        'sys',
        'srv',
        'run',
        'libx32',
        'libexec',
        'lib32',
        'cdrom',
        'timeshift',
        'usr',
        'share',
        'sbin',
        'mnt',
        'proc',
        'lost+found',
        'src',
    ],

    'port'    => env('SFTP_PORT', 22),
    'timeout' => env('SFTP_TIMEOUT', 3),

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_DAY,
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings_required' => env('SFTP_SETTINGS_REQUIRED') && is_string(env('SFTP_SETTINGS_REQUIRED'))
        ? explode(',', env('SFTP_SETTINGS_REQUIRED'))
        : [SettingsRequired::SFTP->value],

    'settings' => [
        'SFTP_HOST' => [
            'name'        => 'SFTP_HOST',
            'placeholder' => 'Enter your SFTP Host here without any space',
            'description' => 'SFTP Host',
            'type'        => 'text',
            'rules'       => 'required|url:http,https',
        ],
        'SFTP_PORT' => [
            'name'        => 'SFTP_PORT',
            'placeholder' => 'Enter your SFTP Port here without any space',
            'description' => 'SFTP Port',
            'type'        => 'text',
            'rules'       => 'required|numeric',
        ],
        'SFTP_USERNAME' => [
            'name'        => 'SFTP_USERNAME',
            'placeholder' => 'Enter your SFTP Username here without any space',
            'description' => 'SFTP Username',
            'type'        => 'text',
            'rules'       => 'required|string',
        ],
        'SFTP_PASSWORD' => [
            'name'        => 'SFTP_PASSWORD',
            'placeholder' => 'Enter your SFTP Password here',
            'description' => 'SFTP Password',
            'type'        => 'password',
            'rules'       => 'required|string',
        ],
        'SFTP_PUBLIC_KEY' => [
            'name'        => 'SFTP_PUBLIC_KEY',
            'placeholder' => 'Paste your SFTP Public Key',
            'description' => 'SFTP Public Key',
            'type'        => 'text',
            'optional'    => true,
            'rules'       => 'nullable|string',
        ],
        'SFTP_PRIVATE_KEY' => [
            'name'        => 'SFTP_PRIVATE_KEY',
            'placeholder' => 'Paste your SFTP Private Key',
            'description' => 'SFTP Private Key',
            'type'        => 'password',
            'optional'    => true,
            'rules'       => 'nullable|string',
        ],
        'SFTP_PASSPHRASE' => [
            'name'        => 'SFTP_PASSPHRASE',
            'placeholder' => 'Enter your SFTP Passphrase here',
            'description' => 'SFTP Passphrase',
            'type'        => 'password',
            'optional'    => true,
            'rules'       => 'nullable|string',
        ],
    ],

];
