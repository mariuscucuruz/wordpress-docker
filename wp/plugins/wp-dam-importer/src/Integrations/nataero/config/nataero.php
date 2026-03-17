<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\AssetType;
use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;

$imageFunctions = [
    NataeroFunctionType::HYPER1->value,
];

$videoFunctions = [
    NataeroFunctionType::HYPER1->value,
];

$audioFunctions = [
    //
];

$convertableFileTypes = [
    AssetType::Image,
    AssetType::PDF,
    AssetType::Audio,
    AssetType::Video,
];

return [
    'name'         => 'nataero',
    'key'          => 'NATAERO',
    'key_prefix'   => 'NATAERO_',
    'description'  => 'Detect assets with similar visual, audio, and keyframe data.',
    'logo'         => 'nataero.svg',
    'active'       => env('NATAERO_ACTIVE', true),
    'type'         => PackageTypes::FUNCTION->value,
    'instructions' => 'readme.md',

    'token' => env('NATAERO_TOKEN'),

    'webhook_url'            => env('NATAERO_WEBHOOK_URL', url('/webhooks/nataero')),
    'convert_webhook_url'    => env('NATAERO_CONVERT_WEBHOOK_URL', url('/webhooks/nataero-asset-convert')),
    'mediainfo_webhook_url'  => env('NATAERO_MEDIAINFO_WEBHOOK_URL', url('/webhooks/nataero-asset-mediainfo')),
    'exif_webhook_url'       => env('NATAERO_EXIF_WEBHOOK_URL', url('/webhooks/nataero-asset-exif')),
    'hyper1_webhook_url'     => env('NATAERO_HYPER1_WEBHOOK_URL', url('/webhooks/nataero-asset-hyper1')),
    'sneakpeek_webhook_url'  => env('NATAERO_SNEAKPEEK_WEBHOOK_URL', url('/webhooks/nataero-asset-sneakpeek')),
    'webhook_signing_secret' => env('NATAERO_WEBHOOK_SECRET'),
    'query_base_url'         => env('NATAERO_QUERY_BASE_URL') . env('NATAERO_VERSION', 'v1'),
    'version'                => env('NATAERO_VERSION', 'v1'),
    'image_functions'        => $imageFunctions,
    'video_functions'        => $videoFunctions,
    'audio_functions'        => $audioFunctions,
    'functions'              => [...$imageFunctions, ...$videoFunctions, ...$audioFunctions],
    'convert_file_types'     => $convertableFileTypes,

    'video_duration_limit' => 720000, // 12 minutes
];
