<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;

return [
    'name'         => 'sneakpeek',
    'description'  => 'A tool that utilizes the FFMpeg library to process multimedia files, allowing users to preview, convert, and edit video and audio content efficiently and effectively.',
    'logo'         => 'sneakpeek.png',
    'active'       => env('SNEAKPEEK_ACTIVE', true),
    'type'         => PackageTypes::FUNCTION->value,
    'instructions' => 'readme.md',

    'ffmpeg'  => env('FFMPEG_BINARIES', '/usr/local/bin/ffmpeg'),
    'ffprobe' => env('FFPROBE_BINARIES', '/usr/local/bin/ffprobe'),

    'fps'                => env('SNEAKPEEK_FPS', '1/5'),
    'sprite_image_count' => env('SNEAKPEEK_SPRITE_IMAGES', 50),
    'scale'              => env('SNEAKPEEK_SCALE', '320'),

    'single_width'  => 1280,
    'single_height' => 720,
];
