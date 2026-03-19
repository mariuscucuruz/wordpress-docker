<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits;

use Closure;
use Throwable;
use Aws\Result;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionTypes;

trait Segmentable
{
    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    public function processSegment(File $file): void
    {
        // Transcribe is only for audio and video files.
        if ($file->type === FunctionsType::Image->value) {
            return;
        }

        $existingTask = $file->rekognitionTasks()->where('job_type', RekognitionTypes::SEGMENT)->first();

        if ($existingTask?->analyzed) {
            $this->log(__FUNCTION__ . ' already analyzed');

            return;
        }

        if ($this->foundSimilarFileAlreadyProcessed($file, RekognitionTypes::SEGMENT)) {
            return;
        }

        $segment = $this->startSegment($file);

        if (
            ! $segment
            || ! is_object($segment)
            || empty(data_get($segment, 'JobId'))
        ) {
            $this->handleNoJobId($file, RekognitionTypes::SEGMENT);

            return;
        }

        $this->saveJobTask($file, RekognitionTypes::SEGMENT, data_get($segment, 'JobId'));
    }

    public function startSegment(File $file)
    {
        if (! $file->view_url) {
            $this->log(__FUNCTION__ . ' view_url is empty', 'warn');

            return null;
        }

        return rescue(function () use ($file) {
            // 🔄 Retry segment detection in case of AWS rate limits
            return retry(
                times: config('rekognition.retry_on_failure', 3),
                callback: function () use ($file) {
                    return $this->rekognitionClient->startSegmentDetection([
                        'ClientRequestToken'  => str()->random(),
                        'JobTag'              => config('rekognition.face_job_tag'),
                        'NotificationChannel' => [
                            'RoleArn'     => config('rekognition.iam_role'),
                            'SNSTopicArn' => config('rekognition.sns_topic'),
                        ],
                        'SegmentTypes' => ['SHOT', 'TECHNICAL_CUE'],
                        'Video'        => [
                            'S3Object' => [
                                'Bucket' => config('rekognition.bucket'),
                                'Name'   => $file->originalViewUrl,
                            ],
                        ],
                    ]);
                },
                sleepMilliseconds: fn ($attempt) => (2 ** $attempt) * config('rekognition.retry_in_milliseconds'), // 🔄 Exponential backoff
                when: fn ($e) => is_retryable_aws_error($e) && ! is_concurrent_limit_exceeded($e) // 🔍 Catch retryable errors
            );
        }, function (Throwable $e) use ($file) {
            $this->handleFailure($file, RekognitionTypes::SEGMENT, $e);

            throw $e;
        });
    }

    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    public function getSegmentDetectionById(string|int $JobId, string|int|null $NextToken = null): Result|Closure|null
    {
        if (! $JobId || $JobId == 0) {
            return null;
        }

        return rescue(function () use ($JobId, $NextToken) {
            return retry(
                times: config('rekognition.retry_on_failure'),
                callback: fn () => $this->rekognitionClient->getSegmentDetection([
                    'JobId'     => $JobId,
                    'NextToken' => $NextToken,
                ]),
                sleepMilliseconds: fn (int $attempt) => (2 ** $attempt) * config('rekognition.retry_in_milliseconds'),
                when: fn ($e) => is_retryable_aws_error($e) && ! is_concurrent_limit_exceeded($e)
            );
        }, function (Throwable $e) {
            $this->log(__FUNCTION__ . ' ' . $e->getMessage(), 'error');

            return null;
        });
    }
}
