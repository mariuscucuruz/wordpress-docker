<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionTypes;

return [
    'name'         => 'rekognition', // Do not change this!
    'description'  => 'A machine learning service that analyzes images and videos for object and scene detection, facial recognition, and content moderation.',
    'logo'         => 'rekognition.png',
    'active'       => env('REKOGNITION_ACTIVE', true),
    'type'         => PackageTypes::FUNCTION->value,
    'instructions' => 'readme.md',

    'credentials' => [
        'key'    => env('AWS_ID') ?? env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET') ?? env('AWS_SECRET_ACCESS_KEY'),
    ],

    'ai_objects' => [
        'texts',
        'transcribes',
        'celebrities',
        'segments',
    ],

    'ai_tables' => collect(RekognitionTypes::cases())
        ->map(fn (RekognitionTypes $type) => str($type->value)
            ->singular()
            ->toString() . '_detections'
        )
        ->reject('segment_detections')
        ->all(),

    'old_ai_tables' => collect(RekognitionTypes::cases())
        ->map(fn (RekognitionTypes $type) => $type->value)
        ->reject('segments')
        ->all(),

    'version'                   => 'latest',
    'region'                    => env('AWS_DEFAULT_REGION', 'eu-west-2'),
    'bucket'                    => env('AWS_BUCKET'),
    'iam_role'                  => env('AWS_IAM_ROLE_ARN'),
    'sns_topic'                 => env('AWS_SNS_TOPIC_ARN'),
    'min_confidence'            => env('MIN_CONFIDENCE', 90),
    'moderation_min_confidence' => env('MODERATION_MIN_CONFIDENCE', 70),
    'celebrity_min_confidence'  => env('CELEBRITY_MIN_CONFIDENCE', 95),
    'max_open_jobs'             => env('MAX_OPEN_JOBS', 20),
    'max_image_labels'          => env('MAX_IMAGE_LABELS', 30),
    'max_video_labels'          => env('MAX_VIDEO_LABELS', 0),
    'label_job_tag'             => env('LABEL_JOB_TAG', 'label-detection'),
    'text_job_tag'              => env('TEXT_JOB_TAG', 'text-detection'),
    'face_job_tag'              => env('FACE_JOB_TAG', 'face-detection'),
    'segment_job_tag'           => env('SEGMENT_JOB_TAG', 'segment-detection'),
    'moderation_job_tag'        => env('MODERATION_JOB_TAG', 'moderation-detection'),
    'transcribe_language_code'  => env('TRANSCRIBE_LANGUAGE_CODE', 'en-US'),
    'celebrity_image'           => [
        'api'     => env('CELEBRITY_IMAGE_API', 'https://www.wikidata.org/w/api.php'),
        'commons' => env('CELEBRITY_IMAGE_COMMONS', 'https://commons.wikimedia.org/wiki/Special'),
    ],
    'transcribe_languages' => [
        'en-IE', // English (Ireland)
        'ar-AE', // Arabic (Gulf)
        'te-IN', // Telugu (India)
        'zh-TW', // Chinese (Traditional, Taiwan)
        'en-US', // English (United States)
        'en-AB', // English (Scottish)
        'en-IN', // English (India)
        'en-ZA', // English (South Africa)
        'en-WL', // English (Welsh)
        'pt-BR', // Portuguese (Brazil)
        'fr-CA', // French (Canada)
        'es-US', // Spanish (United States)
        'de-DE', // German (Germany)
        'fa-IR', // Persian (Iran)
        'he-IL', // Hebrew (Israel)
        'tr-TR', // Turkish (Turkey)
        'fr-FR', // French (France)
        'it-IT', // Italian (Italy)
        'ja-JP', // Japanese (Japan)
        'nl-NL', // Dutch (Netherlands)
        'ko-KR', // Korean (South Korea)
        'en-GB', // English (United Kingdom)
        'en-AU', // English (Australia)
        'en-NZ', // English (New Zealand)
        'es-ES', // Spanish (Spain)
        'pt-PT', // Portuguese (Portugal)
        'de-CH', // German (Swiss)
    ],

    'transcribe_media_format' => env('TRANSCRIBE_MEDIA_FORMAT', 'mp4'),

    /*
     * Default to 5 tries on failure.
     */
    'retry_on_failure'      => env('REKOGNITION_RETRY_ON_FAILURE', 3),
    'retry_in_milliseconds' => env('REKOGNITION_RETRY_IN_MILLISECONDS', 5000),

    /*
     * Job throttling settings to prevent hitting AWS concurrent job limit (20 jobs).
     * max_concurrent_jobs: Maximum concurrent jobs to allow (default 18 to leave buffer).
     * throttle_release_delay: Seconds to wait when throttled before retrying.
     */
    'max_concurrent_jobs'    => env('REKOGNITION_MAX_CONCURRENT_JOBS', 18),
    'throttle_release_delay' => env('REKOGNITION_THROTTLE_DELAY', 45),

    /*
     * To see these errors
     * SEE: vendor/aws/aws-sdk-php/src/RetryMiddlewareV2.php
     */
    'retryable_errors' => [
        'SlowDown',
        'Throttling',
        'RequestThrottled',
        'ThrottledException',
        'ThrottlingException',
        'RequestLimitExceeded',
        'EC2ThrottledException',
        'BandwidthLimitExceeded',
        'LimitExceededException',
        'PriorRequestNotComplete',
        'TooManyRequestsException',
        'RequestThrottledException',
        'TransactionInProgressException',
        'ProvisionedThroughputExceededException',
    ],

    'settings' => [
        'AWS_ACCESS_KEY_ID' => [
            'name'        => 'AWS_ACCESS_KEY_ID',
            'placeholder' => 'Enter your AWS Access Key ID here without any space',
            'description' => 'AWS Access Key ID',
            'type'        => 'text',
        ],
        'AWS_SECRET_ACCESS_KEY' => [
            'name'        => 'AWS_SECRET_ACCESS_KEY',
            'placeholder' => 'Enter your AWS Secret Access Key here without any space',
            'description' => 'AWS Secret Access Key',
            'type'        => 'password',
        ],
        'AWS_DEFAULT_REGION' => [
            'name'        => 'AWS_DEFAULT_REGION',
            'placeholder' => 'Enter your AWS Default Region here without any space',
            'description' => 'AWS Default Region',
            'type'        => 'text',
        ],
        'AWS_BUCKET' => [
            'name'        => 'AWS_BUCKET',
            'placeholder' => 'Enter your AWS Bucket here without any space',
            'description' => 'AWS Bucket',
            'type'        => 'text',
        ],
        'AWS_IAM_ROLE_ARN' => [
            'name'        => 'AWS_IAM_ROLE_ARN',
            'placeholder' => 'Enter your AWS IAM Role ARN here without any space',
            'description' => 'AWS IAM Role ARN',
            'type'        => 'text',
        ],
        'AWS_SNS_TOPIC_ARN' => [
            'name'        => 'AWS_SNS_TOPIC_ARN',
            'placeholder' => 'Enter your AWS SNS Topic ARN here without any space',
            'description' => 'AWS SNS Topic ARN',
            'type'        => 'text',
        ],
    ],
];
