<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\MediaStorageType;

/*
|--------------------------------------------------------------------------
| Packages Default Configurations
|--------------------------------------------------------------------------
|
| These are usually come from the service's meta table which is populated
| already by the service's package. However, if the service's meta table
| is empty, then these are the default configs that will be used.
|
| NOTE: do not use multi denominational arrays or key value pairs here.
|       only use single dimensional arrays with no keys:
|
*/

// SEE: https://clickon.atlassian.net/wiki/spaces/MED/pages/1502052381/Supported+file+formats
// SEE: https://docs.aws.amazon.com/mediaconvert/latest/ug/reference-codecs-containers-input.html

// Supported file formats
$videoExtensions = [
    'h264', '264', 'h265', '265', 'flv', 'f4v', 'mxf', 'm2v', 'mp4', 'mov', 'mkv', 'avi',
    'webm', 'mpg', 'mpeg',  'mpe', 'mpv', 'm1v', 'dat', 'm2t', 'm2p', 'mts', 'm4v', '3gp',
    '3g2', 'wmv', 'asf',  'ts',
];

// Mediaconvert compatability
// SEE: https://docs.aws.amazon.com/mediaconvert/latest/ug/reference-codecs-containers-input.html
$audioExtensions = [
    'aac', 'ac3', 'eac3', 'flac', 'mp3', 'mpg', 'opus', 'wav', 'ogg', 'wma',
];

$documentExtensions = [
    'pdf', 'ai',
];

$opaqueImageExtensions = [
    'jpg', 'jpeg', 'cr2',
];

$transparentImageExtensions = [
    'png', 'bmp', 'webp', 'tif', 'tiff', 'heic', 'svg', 'ico',
];

$animatedImageExtensions = [
    'gif',
];

$photoshopExtensions = [
    'psd', 'ai',
];

$rawImageExtensions = [
    'dng', 'crw', 'cs1', 'bay', 'mrw', 'nksc', 'j6i', 'x3f',
    'eip', 'erf', 'cxi', 'raf', 'gpr', '3fr', 'fff', 'kc2',
    'dcr', 'k25', 'kdc', 'mos', 'rwl', 'mfw', 'mef', 'mdc',
    'nef', 'nrw', 'orf', 'rw2', 'pef', 'iiq', 'raw', 'rwz',
    'sr2', 'srf',
    // not supported by ImageMagick
    // 'arw', 'ari', 'cr2', 'cr3', 'srw',
];

/*
    The following file formats have conversion issues - But can be played back in the browser directly.
    ICO: Not supported by ImageMagick / ImageIntervention, Encountering the following logs:
        1. Image source not readable
        2. No decoded delegate for this image format
        3. Unable to read image from binary data
*/
$nonProcessedImageExtensions = [
    'ico',
];

$extensionsAndMimeTypes = [
    'h264'        => 'video/h264',
    '264'         => 'video/h264',
    'h265'        => 'video/h265',
    '265'         => 'video/h265',
    'flv'         => 'video/x-flv',
    'f4v'         => 'video/x-f4v',
    'mxf'         => 'application/mxf',
    'ts'          => 'video/mp2t',
    'wmv'         => 'video/x-ms-wmv',
    'asf'         => 'video/x-ms-asf',
    'mov'         => 'video/quicktime',
    'mp4'         => 'video/mp4',
    'mkv'         => 'video/x-matroska',
    'avi'         => 'video/x-msvideo',
    'webm'        => 'video/webm',
    'ogv'         => 'video/ogg',
    'mpg'         => 'video/mpeg',
    'mpeg'        => 'video/mpeg',
    'mpe'         => 'video/mpeg',
    'mpv'         => 'video/mpeg',
    'm1v'         => 'video/mpeg',
    'm2t'         => 'video/mpeg',
    'm2p'         => 'video/mpeg',
    'mts'         => 'video/mpeg',
    'm2v'         => 'video/mpeg',
    'm4v'         => 'video/x-m4v',
    'mp3'         => 'audio/mpeg',
    'wav'         => 'audio/wav',
    'ogg'         => 'audio/ogg',
    '3gp'         => 'video/3gpp',
    '3g2'         => 'video/3gpp2',
    'jpg'         => 'image/jpeg',
    'jpeg'        => 'image/jpeg',
    'png'         => 'image/png',
    'bmp'         => 'image/bmp',
    'webp'        => 'image/webp',
    'gif'         => 'image/gif',
    'tif'         => 'image/tiff',
    'tiff'        => 'image/tiff',
    'heic'        => 'image/heif',
    'cr2'         => 'image/x-canon-cr2',
    'nef'         => 'image/x-nikon-nef',
    'orf'         => 'image/x-olympus-orf',
    'arw'         => 'image/x-sony-arw',
    'pdf'         => 'application/pdf',
    'psd'         => 'image/vnd.adobe.photoshop',
    'svg'         => 'image/svg+xml',
    'illustrator' => 'application/pdf',
    'ai'          => 'application/postscript',
];

$imageExtensions = [
    ...$opaqueImageExtensions,
    ...$transparentImageExtensions,
    ...$animatedImageExtensions,
    ...$photoshopExtensions,
    ...$rawImageExtensions,
];

$documentInterventionConversionExtensions = [
    'pdf' => 'jpg',
];

$recommendedOutboundServices = [
    interface_basename(MariusCucuruz\DAMImporter\Integrations\Vimeo\Vimeo::class),
    interface_basename(MariusCucuruz\DAMImporter\Integrations\Tiktok\Tiktok::class),
    interface_basename(MariusCucuruz\DAMImporter\Integrations\Metaads\Metaads::class),
    interface_basename(MariusCucuruz\DAMImporter\Integrations\Facebook\Facebook::class),
    interface_basename(MariusCucuruz\DAMImporter\Integrations\Instagram\Instagram::class),
    interface_basename(MariusCucuruz\DAMImporter\Integrations\Youtube\Youtube::class),
    interface_basename(MariusCucuruz\DAMImporter\Integrations\Pinterest\Pinterest::class),
    interface_basename(MariusCucuruz\DAMImporter\Integrations\WebSweep\WebSweep::class),
    interface_basename(MariusCucuruz\DAMImporter\Integrations\GoogleAds\GoogleAds::class),
    interface_basename(MariusCucuruz\DAMImporter\Integrations\TikTokAds\TikTokAds::class),
    interface_basename(MariusCucuruz\DAMImporter\Integrations\LinkedIn\LinkedIn::class),
];

$jwtCredentialsConversion = [
    'integration.technicalAccount.clientId'     => 'clientId',
    'integration.technicalAccount.clientSecret' => 'clientSecret',
    'integration.id'                            => 'technicalAccountId',
    'integration.org'                           => 'orgId',
    'integration.metascopes'                    => 'metascopes',
    'integration.imsEndpoint'                   => 'ims',
    'integration.privateKey'                    => 'privateKey',
];

return [
    'meta' => [
        'folders'                        => null,
        'video_extensions'               => $videoExtensions,
        'image_extensions'               => $imageExtensions,
        'document_extensions'            => $documentExtensions,
        'audio_extensions'               => $audioExtensions,
        'opaque_image_extensions'        => $opaqueImageExtensions,
        'transparent_image_extensions'   => $transparentImageExtensions,
        'non_processed_image_extensions' => $nonProcessedImageExtensions,
        'raw_image_extensions'           => $rawImageExtensions,
        'photoshop_extensions'           => $photoshopExtensions,
        'file_extensions'                => [
            ...$videoExtensions,
            ...$imageExtensions,
            ...$rawImageExtensions,
            ...$audioExtensions,
            ...$documentExtensions,
        ],
        'document_conversion_extensions' => $documentInterventionConversionExtensions,
        'recommended_outbound_services'  => $recommendedOutboundServices,
    ],

    // SEE: https://docs.aws.amazon.com/rekognition/latest/dg/limits.html#quotas
    'max_image_dimensions'           => 10000, // 10K px
    'min_image_dimensions'           => 80, // 80 px
    'max_image_ppe_dimensions'       => 4096, // PPE: Pixels Per Edge
    'image_max_quality'              => 70, // 70 % quality
    'image_min_quality'              => 10, // 10 % quality
    'max_image_size'                 => 15 * 1024 * 1024, // 15 MB
    'max_thumbnail_size'             => 50 * 1024, // 50 KB
    'max_thumbnail_resolution'       => 300, // 300 px
    'pdf_resolution'                 => 200, // 200 px
    'pdf_compressed_quality'         => 95, // 95 %
    'illustrator_compressed_quality' => 95, // 95 %
    /*
     * Amazon S3 supports a maximum of 10,000 parts per upload.
     * If we want to aim 100GB max, then the ideal chunk_size should be minimum 10 MB.
     */
    'chunk_size' => 10, // in MB, so multiply this to 1024 * 1024 in package

    /*
     * Default to 12 minutes (720,000 milliseconds).
     * If you are using config whatever minutes you want, you must multiply by 60 * 1000
     * and set it as milliseconds ie: 720000
     */
    'max_download_millisecond' => env('MAX_DOWNLOAD_MS', 2 * 60 * 60 * 1000), // 2 hours only downloads!
    'max_duration_millisecond' => env('REKOGNITION_MAX_DURATION_MS', 2 * 60 * 1000), // 2-min only videos!
    'max_size_byte'            => env('REKOGNITION_MAX_SIZE_BYTE', 5 * 1024 * 1024 * 1024), // 5GB only image!

    /**
     * Location on YT_DLP binary used for downloads.
     * https://github.com/yt-dlp/yt-dlp
     */
    'yt_dlp' => env('YTDLP', '/usr/local/bin/yt-dlp'),

    /*
    * Image and Video Extensions and Mime Types
    */
    'extensions_and_mime_types' => $extensionsAndMimeTypes,

    /*
    * This is for our own storage do not use this for storage packages
    */
    'directory' => [
        MediaStorageType::originals->name   => strtolower(env('APP_NAME', '')) . DIRECTORY_SEPARATOR . MediaStorageType::originals->value,
        MediaStorageType::derivatives->name => strtolower(env('APP_NAME', '')) . DIRECTORY_SEPARATOR . MediaStorageType::derivatives->value,
        MediaStorageType::thumbnails->name  => strtolower(env('APP_NAME', '')) . DIRECTORY_SEPARATOR . MediaStorageType::thumbnails->value,
        MediaStorageType::sneakpeeks->name  => strtolower(env('APP_NAME', '')) . DIRECTORY_SEPARATOR . MediaStorageType::sneakpeeks->value,
        MediaStorageType::uploads->name     => strtolower(env('APP_NAME', '')) . DIRECTORY_SEPARATOR . MediaStorageType::uploads->value,
        MediaStorageType::data->name        => strtolower(env('APP_NAME', '')) . DIRECTORY_SEPARATOR . MediaStorageType::data->value,
        MediaStorageType::reports->name     => strtolower(env('APP_NAME', '')) . DIRECTORY_SEPARATOR . MediaStorageType::reports->value,
    ],

    /*
     * Every package must have two different download strategies implemented:
     * `temporary` and `multipart`.
     */
    'download_strategy' => env('DOWNLOAD_STRATEGY', 'temporary'),

    'max_download_retries' => env('MAX_DOWNLOAD_RETRIES', 3),

    /** @see \MariusCucuruz\DAMImporter\Models\Service::getDefaultOptions() */
    'defaults' => [
        'sync_mode'     => MariusCucuruz\DAMImporter\Enums\PackageSyncMode::AUTOMATIC,
        'sync_interval' => MariusCucuruz\DAMImporter\Enums\PackageInterval::EVERY_DAY,
        'sync_options'  => [
            MariusCucuruz\DAMImporter\Enums\PackageInterval::EVERY_TWELVE_HOURS,
            MariusCucuruz\DAMImporter\Enums\PackageInterval::EVERY_DAY,
            MariusCucuruz\DAMImporter\Enums\PackageInterval::EVERY_WEEK,
        ],
    ],

    'meta_fields' => [
        'title',
        'description',
        'type',
    ],

    'oauth2_client' => [
        'user_agent' => 'MedialakeAI OAuth Client/1.0',
    ],

    'jwt_credentials_key_conversion' => $jwtCredentialsConversion,

    'oauth_redirect_token' => env('OAUTH_REDIRECT_TOKEN'),

    'oauth_class' => \MariusCucuruz\DAMImporter\Support\EncryptRedirectToken::class,

    'folder_modal_pagination_limit' => 1000,

    'sync_branch_files' => [
        'number_of_services_per_job'    => 5,
        'number_of_services_per_chunk'  => 2,
        'number_of_branches_per_job'    => env('NUMBER_OF_BRANCHES_TO_FILE_SYNC_PER_JOB', 500),
        'branches_per_chunk'            => 30,
        'max_fail_count'                => 3,
        'stale_branch_timeout_in_hours' => 1,
    ],

    'traverse_directory_branch' => [
        'stale_branch_timeout_in_hours'          => 1,
        'max_fail_count'                         => 3,
        'number_of_branches_to_traverse_per_job' => env('NUMBER_OF_BRANCHES_TO_TRAVERSE_PER_JOB', 500),
        'chunk_size'                             => 10,
    ],

    'sync_marketing_tree' => [
        'services_tree_per_hour'          => 5,
        'services_metrics_per_10_minutes' => 5,
    ],
];
