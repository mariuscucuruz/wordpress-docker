<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;

return [
    'name'         => 'mediainfo',
    'key'          => 'MEDIAINFO',
    'description'  => 'A powerful utility that retrieves and displays detailed metadata about audio, video, and image files.',
    'logo'         => 'mediainfo.png',
    'active'       => env('MEDIAINFO_ACTIVE', true),
    'type'         => PackageTypes::FUNCTION->value,
    'instructions' => 'readme.md',

    'command' => env('MEDIAINFO_BINARIES', '/opt/homebrew/bin/mediainfo'),
];
