<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits;

use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionTypes;

trait Transcribeable
{
    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    public function processTranscribes(File $file): void
    {
        // Transcribe is only for audio and video files.
        if ($file->type === FunctionsType::Image->value) {
            return;
        }

        $existingTask = $file->rekognitionTasks()->where('job_type', RekognitionTypes::TRANSCRIBES)->first();

        if ($existingTask?->analyzed) {
            $this->log(__FUNCTION__ . ' already analyzed');

            return;
        }

        if ($this->foundSimilarFileAlreadyProcessed($file, RekognitionTypes::TRANSCRIBES)) {
            return;
        }

        $transcribe = $this->startTranscription($file);

        if (empty($transcribe)) {
            $this->handleNoJobId($file, RekognitionTypes::TRANSCRIBES);

            return;
        }

        $this->saveJobTask($file, RekognitionTypes::TRANSCRIBES, $transcribe);
    }

    public function startTranscription(File $file)
    {
        if (! $file->view_url) {
            $this->log(__FUNCTION__ . ' view_url is empty', 'warning');

            return null;
        }

        return rescue(function () use ($file) {
            // 🔄 Retry transcription in case of AWS rate limits
            return retry(
                times: config('rekognition.retry_on_failure', 3),
                callback: function () use ($file) {
                    $result = $this->transcribeClient->startTranscriptionJob([
                        'Media' => [
                            'MediaFileUri' => 's3://'
                                . config('filesystems.disks.s3.bucket')
                                . DIRECTORY_SEPARATOR
                                . $file->originalViewUrl,
                        ],
                        'MediaFormat'               => config('rekognition.transcribe_media_format'),
                        'TranscriptionJobName'      => str()->random(10),
                        'OutputBucketName'          => config('filesystems.disks.s3.bucket'),
                        'IdentifyMultipleLanguages' => true,
                        'LanguageOptions'           => config('rekognition.transcribe_languages'),
                    ]);

                    return $result['TranscriptionJob']['TranscriptionJobName'];
                },
                sleepMilliseconds: fn ($attempt) => (2 ** $attempt) * config('rekognition.retry_in_milliseconds'), // 🔄 Exponential backoff
                when: fn ($e) => is_retryable_aws_error($e) && ! is_concurrent_limit_exceeded($e) // 🔍 Catch retryable errors
            );
        }, function (Throwable $e) use ($file) {
            $this->handleFailure($file, RekognitionTypes::TRANSCRIBES, $e);

            return null;
        });
    }

    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    public function getTranscribeById(string|int|null $jobName)
    {
        if (! $jobName) {
            $this->log(__FUNCTION__ . ' jobName is empty', 'error');

            return null;
        }

        return rescue(function () use ($jobName) {
            return retry(
                times: config('rekognition.retry_on_failure'),
                callback: fn () => $this->transcribeClient->getTranscriptionJob([
                    'TranscriptionJobName' => (string) $jobName]
                ),
                sleepMilliseconds: fn (int $attempt) => (2 ** $attempt) * config('rekognition.retry_in_milliseconds'),
                when: fn ($e) => is_retryable_aws_error($e) && ! is_concurrent_limit_exceeded($e)
            );
        }, function (Throwable $e) {
            $this->log(__FUNCTION__ . ' ' . $e->getMessage(), 'error');

            return null;
        });
    }
}
