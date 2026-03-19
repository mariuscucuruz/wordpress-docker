<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits;

use Closure;
use Throwable;
use Aws\Result;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionTypes;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionJobStatus;

trait Textable
{
    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    public function processTexts(File $file): void
    {
        $existingTask = $file->rekognitionTasks()->where('job_type', RekognitionTypes::TEXTS)->first();

        if ($existingTask?->analyzed) {
            $this->log(__FUNCTION__ . ' already analyzed');

            return;
        }

        if ($this->foundSimilarFileAlreadyProcessed($file, RekognitionTypes::TEXTS)) {
            return;
        }

        $textDetection = $this->startTextDetection($file);

        if (
            ! $textDetection
            || ! is_object($textDetection)
            || empty(data_get($textDetection, 'JobId'))
        ) {
            $this->handleNoJobId($file, RekognitionTypes::TEXTS);

            return;
        }

        $this->saveJobTask($file, RekognitionTypes::TEXTS, data_get($textDetection, 'JobId'));
    }

    public function startTextDetection(File $file)
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
            if ($file->type === FunctionsType::Image->value && $file->extension !== 'gif') {
                // 🔄 Retry text detection for images in case of AWS rate limits
                return retry(
                    times: config('rekognition.retry_on_failure', 3),
                    callback: function () use ($file) {
                        $result = $this->rekognitionClient->detectText([
                            'Image' => [
                                'S3Object' => [
                                    'Bucket' => config('rekognition.bucket'),
                                    'Name'   => $file->originalViewUrl,
                                ],
                            ],
                        ]);

                        $this->saveJobTask(
                            $file,
                            RekognitionTypes::TEXTS,
                            0, // Image jobs are processed immediately
                            RekognitionJobStatus::SUCCEEDED,
                            $result['TextDetections'] ?? []
                        );

                        return $result;
                    },
                    sleepMilliseconds: fn ($attempt) => (2 ** $attempt) * config('rekognition.retry_in_milliseconds'), // 🔄 Exponential backoff
                    when: fn ($e) => is_retryable_aws_error($e) && ! is_concurrent_limit_exceeded($e) // 🔍 Catch retryable errors
                );
            }

            // 🔄 Retry text detection for videos in case of AWS rate limits
            return retry(
                times: config('rekognition.retry_on_failure', 3),
                callback: function () use ($file) {
                    return $this->rekognitionClient->startTextDetection([
                        'ClientRequestToken'  => str()->random(),
                        'JobTag'              => config('rekognition.text_job_tag'),
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
                when: fn ($e) => is_retryable_aws_error($e) && ! is_concurrent_limit_exceeded($e) // 🔍 Catch rate-limit errors
            );
        }, function (Throwable $e) use ($file) {
            $this->handleFailure($file, RekognitionTypes::TEXTS, $e);

            throw $e;
        });
    }

    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    public function getTextDetectionById(string|int $JobId, string|int|null $NextToken = null): Result|Closure|null
    {
        if (! $JobId || $JobId == 0) {
            return null;
        }

        return rescue(function () use ($JobId, $NextToken) {
            return retry(
                times: config('rekognition.retry_on_failure'),
                callback: fn () => $this->rekognitionClient->getTextDetection([
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
