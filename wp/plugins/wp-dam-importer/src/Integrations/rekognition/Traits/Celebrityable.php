<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits;

use Closure;
use Throwable;
use Aws\Result;
use MariusCucuruz\DAMImporter\Enums\AssetType;
use Illuminate\Database\Eloquent\Model;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionTypes;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionJobStatus;

trait Celebrityable
{
    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    public function processCelebrities(Model $file): void
    {
        $existingTask = $file->rekognitionTasks()->where('job_type', RekognitionTypes::CELEBRITIES)->first();

        if ($existingTask?->analyzed) {
            $this->log(__FUNCTION__ . ' already analyzed');

            return;
        }

        if ($this->foundSimilarFileAlreadyProcessed($file, RekognitionTypes::CELEBRITIES)) {
            return;
        }

        $celebrityDetection = $this->startCelebrityDetection($file);

        if (
            ! $celebrityDetection
            || ! is_object($celebrityDetection)
            || empty(data_get($celebrityDetection, 'JobId'))
        ) {
            $this->handleNoJobId($file, RekognitionTypes::CELEBRITIES);

            return;
        }

        $this->saveJobTask($file, RekognitionTypes::CELEBRITIES, data_get($celebrityDetection, 'JobId'));
    }

    public function startCelebrityDetection(Model $file)
    {
        if (! $file->view_url) {
            $this->log(__FUNCTION__ . ' view_url is empty', 'warning');

            return null;
        }

        if (! $file->type) {
            $this->log(__FUNCTION__ . ' file type is empty', 'warning');

            return null;
        }

        return rescue(function () use ($file) {
            if ($file->extension !== 'gif' && AssetType::isImage($file->type)) {
                // 🔄 Retry celebrity recognition for images in case of AWS rate limits
                return retry(
                    times: config('rekognition.retry_on_failure', 3),
                    callback: function () use ($file) {
                        $result = $this->rekognitionClient->recognizeCelebrities([
                            'Image' => [
                                'S3Object' => [
                                    'Bucket' => config('rekognition.bucket'),
                                    'Name'   => $file->originalViewUrl,
                                ],
                            ],
                        ]);

                        $result = $result->toArray();
                        $this->saveJobTask(
                            $file,
                            RekognitionTypes::CELEBRITIES,
                            0, // Image jobs are processed immediately
                            RekognitionJobStatus::SUCCEEDED,
                            $result['CelebrityFaces'] ? $result : []
                        );

                        return $result;
                    },
                    sleepMilliseconds: fn ($attempt) => (2 ** $attempt) * config('rekognition.retry_in_milliseconds'), // 🔄 Exponential backoff
                    when: fn ($e) => is_retryable_aws_error($e) && ! is_concurrent_limit_exceeded($e) // 🔍 Catch retryable errors
                );
            }

            // 🔄 Retry celebrity recognition for videos in case of AWS rate limits
            return retry(
                times: config('rekognition.retry_on_failure', 3),
                callback: function () use ($file) {
                    return $this->rekognitionClient->startCelebrityRecognition([
                        'ClientRequestToken'  => str()->random(),
                        'JobTag'              => config('rekognition.face_job_tag'),
                        'NotificationChannel' => [
                            'RoleArn'     => config('rekognition.iam_role'),
                            'SNSTopicArn' => config('rekognition.sns_topic'),
                        ],
                        'Video' => [
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
            $this->handleFailure($file, RekognitionTypes::CELEBRITIES, $e);

            throw $e;
        });
    }

    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    public function getCelebrityDetectionById(string|int $JobId, string|int|null $NextToken = null): Result|Closure|null
    {
        if (! $JobId || $JobId == 0) {
            return null;
        }

        return rescue(function () use ($JobId, $NextToken) {
            return retry(
                times: config('rekognition.retry_on_failure'),
                callback: fn () => $this->rekognitionClient->getCelebrityRecognition([
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
