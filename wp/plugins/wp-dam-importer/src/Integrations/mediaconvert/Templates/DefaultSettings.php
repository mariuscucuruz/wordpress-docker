<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Templates;

use MariusCucuruz\DAMImporter\Support\Path;
use Illuminate\Database\Eloquent\Model;

class DefaultSettings
{
    private array $settings;

    public function __construct(Model $file, array $overrides = [])
    {
        $settings = match ($file->type) {
            'audio' => $this->getAudioSettings($file),
            default => $this->getDefaultSettings($file),
        };
        $this->settings = array_replace_recursive($settings, $overrides);
    }

    public function toArray(): array
    {
        return $this->settings;
    }

    public static function defaultFilePath(Model $file, $withS3Prefix = false): string
    {
        $fileName = $file->type . '-' . config('mediaconvert.output_profile.default') . '-default';
        $derivativePath = Path::join(config('manager.directory.derivatives'), $file->id, $fileName);

        if ($withS3Prefix) {
            return Path::join('s3://' . config('mediaconvert.bucket'), $derivativePath);
        }

        return $derivativePath . data_get(config('mediaconvert.output_extensions'), $file->type);
    }

    private function getDefaultSettings(Model $file): array
    {
        return [
            'UserMetadata' => [],
            'Role'         => config('mediaconvert.role'),
            'OutputGroups' => [
                [
                    'Name'                => 'File Group',
                    'OutputGroupSettings' => [
                        'Type'              => 'FILE_GROUP_SETTINGS',
                        'FileGroupSettings' => [
                            'Destination'         => self::defaultFilePath($file, true),
                            'DestinationSettings' => [
                                'S3Settings' => [
                                    'AccessControl' => [
                                        'CannedAcl' => 'BUCKET_OWNER_FULL_CONTROL',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'Outputs' => [
                        [
                            'VideoDescription' => [
                                'ScalingBehavior'   => 'DEFAULT',
                                'TimecodeInsertion' => 'DISABLED',
                                'AntiAlias'         => 'ENABLED',
                                'Sharpness'         => 50,
                                'CodecSettings'     => [
                                    'Codec'        => 'H_264',
                                    'H264Settings' => [
                                        'InterlaceMode'                       => 'PROGRESSIVE',
                                        'NumberReferenceFrames'               => 3,
                                        'Syntax'                              => 'DEFAULT',
                                        'Softness'                            => 0,
                                        'GopClosedCadence'                    => 1,
                                        'GopSize'                             => 48,
                                        'Slices'                              => 1,
                                        'GopBReference'                       => 'DISABLED',
                                        'SlowPal'                             => 'DISABLED',
                                        'SpatialAdaptiveQuantization'         => 'ENABLED',
                                        'TemporalAdaptiveQuantization'        => 'ENABLED',
                                        'FlickerAdaptiveQuantization'         => 'DISABLED',
                                        'EntropyEncoding'                     => 'CABAC',
                                        'Bitrate'                             => 4500000,
                                        'CodecProfile'                        => 'HIGH',
                                        'Telecine'                            => 'NONE',
                                        'MinIInterval'                        => 0,
                                        'AdaptiveQuantization'                => 'HIGH',
                                        'CodecLevel'                          => 'AUTO',
                                        'FieldEncoding'                       => 'PAFF',
                                        'SceneChangeDetect'                   => 'ENABLED',
                                        'QualityTuningLevel'                  => 'SINGLE_PASS_HQ',
                                        'FramerateConversionAlgorithm'        => 'DUPLICATE_DROP',
                                        'UnregisteredSeiTimecode'             => 'DISABLED',
                                        'GopSizeUnits'                        => 'FRAMES',
                                        'ParControl'                          => 'INITIALIZE_FROM_SOURCE',
                                        'NumberBFramesBetweenReferenceFrames' => 3,
                                        'RepeatPps'                           => 'DISABLED',
                                        'HrdBufferSize'                       => 9000000,
                                        'HrdBufferInitialFillPercentage'      => 90,
                                        'FramerateControl'                    => 'INITIALIZE_FROM_SOURCE',
                                        'RateControlMode'                     => 'CBR',
                                    ],
                                ],
                                'AfdSignaling'      => 'NONE',
                                'DropFrameTimecode' => 'ENABLED',
                                'RespondToAfd'      => 'NONE',
                                'ColorMetadata'     => 'INSERT',
                                // To evenly scale from your input resolution: Leave Width blank and enter a value for Height.
                                // For example, if your input is 1920x1080 and you set Height to 720, your output will be 1280x720.
                                // NOTE: Aspect ratio needs to follow original - Rekognition does not work optimally for vertical aspect ratios forced to 16:9
                                // FE support all aspect ratios for video content
                                // https://docs.aws.amazon.com/cli/latest/reference/mediaconvert/create-job.html
                                'Height' => config('mediaconvert.output_profile.default'),
                            ],
                            'AudioDescriptions' => [
                                [
                                    'AudioTypeControl' => 'FOLLOW_INPUT',
                                    'CodecSettings'    => [
                                        'Codec'       => 'AAC',
                                        'AacSettings' => [
                                            'AudioDescriptionBroadcasterMix' => 'NORMAL',
                                            'Bitrate'                        => 96000,
                                            'RateControlMode'                => 'CBR',
                                            'CodecProfile'                   => 'LC',
                                            'CodingMode'                     => 'CODING_MODE_2_0',
                                            'RawFormat'                      => 'NONE',
                                            'SampleRate'                     => 48000,
                                            'Specification'                  => 'MPEG4',
                                        ],
                                    ],
                                    'LanguageCodeControl' => 'FOLLOW_INPUT',
                                ],
                            ],
                            'ContainerSettings' => [
                                'Container'   => 'MP4',
                                'Mp4Settings' => [
                                    'CslgAtom'      => 'INCLUDE',
                                    'FreeSpaceBox'  => 'EXCLUDE',
                                    'MoovPlacement' => 'PROGRESSIVE_DOWNLOAD',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'AdAvailOffset' => 0,
            'Inputs'        => [
                [
                    'AudioSelectors' => [
                        'Audio Selector 1' => [
                            'Offset'           => 0,
                            'DefaultSelection' => 'DEFAULT',
                            'SelectorType'     => 'TRACK',
                            'ProgramSelection' => 1,
                        ],
                    ],
                    'VideoSelector' => [
                        'ColorSpace' => 'FOLLOW',
                        'Rotate'     => 'AUTO',
                    ],
                    'FilterEnable'   => 'AUTO',
                    'PsiControl'     => 'USE_PSI',
                    'FilterStrength' => 0,
                    'DeblockFilter'  => 'DISABLED',
                    'DenoiseFilter'  => 'DISABLED',
                    'TimecodeSource' => 'EMBEDDED',
                    'FileInput'      => $file->getOriginal('download_url'),
                ],
            ],
        ];
    }

    private function getAudioSettings(Model $file): array
    {
        return [
            'UserMetadata' => [],
            'Role'         => config('mediaconvert.role'),

            'TimecodeConfig' => [
                'Source' => 'ZEROBASED',
            ],
            'OutputGroups' => [
                [
                    'Name'    => 'File Group',
                    'Outputs' => [
                        [
                            'ContainerSettings' => [
                                'Container' => 'RAW',
                            ],
                            'AudioDescriptions' => [
                                [
                                    'AudioSourceName' => 'Audio Selector 1',
                                    'CodecSettings'   => [
                                        'Codec'       => 'MP3',
                                        'Mp3Settings' => [
                                            'Bitrate'         => 192000,
                                            'RateControlMode' => 'CBR',
                                        ],
                                    ],
                                ],
                            ],
                            'Extension' => 'mp3',
                        ],
                    ],
                    'OutputGroupSettings' => [
                        'Type'              => 'FILE_GROUP_SETTINGS',
                        'FileGroupSettings' => [
                            'Destination'         => self::defaultFilePath($file, true),
                            'DestinationSettings' => [
                                'S3Settings' => [
                                    'AccessControl' => [
                                        'CannedAcl' => 'BUCKET_OWNER_FULL_CONTROL',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'Inputs' => [
                [
                    'AudioSelectors' => [
                        'Audio Selector 1' => [
                            'DefaultSelection' => 'NOT_DEFAULT',
                            'SelectorType'     => 'TRACK',
                        ],
                    ],
                    'TimecodeSource' => 'ZEROBASED',
                    'FileInput'      => $file->getOriginal('download_url'),
                ],
            ],

            'BillingTagsSource'    => 'JOB',
            'AccelerationSettings' => [
                'Mode' => 'DISABLED',
            ],
            'StatusUpdateInterval' => 'SECONDS_60',
            'Priority'             => 0,
        ];
    }
}
