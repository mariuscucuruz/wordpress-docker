<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;

return [
    'name'         => 'exif',
    'key'          => 'EXIF',
    'description'  => 'A versatile command-line utility for reading, writing, and manipulating metadata in image, audio, and video files.',
    'logo'         => 'exif.png',
    'active'       => env('EXIF_ACTIVE', true),
    'type'         => PackageTypes::FUNCTION->value,
    'instructions' => 'readme.md',

    'tool' => env('EXIFTOOL_BINARIES', '/opt/homebrew/bin/exiftool'),
];
