<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\AdminSetting;
use Aws\Rekognition\RekognitionClient;
use Illuminate\Database\Eloquent\Model;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits\Textable;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits\Segmentable;
use Aws\TranscribeService\TranscribeServiceClient;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits\Celebrityable;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits\Transcribeable;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionTypes;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\RekognitionTask;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionJobStatus;
use MariusCucuruz\DAMImporter\Interfaces\PackageTypes\IsFunction;

class Rekognition implements IsFunction
{
    use Celebrityable,
        Loggable,
        Segmentable,
        Textable,
        Transcribeable;

    private RekognitionClient $rekognitionClient;

    private TranscribeServiceClient $transcribeClient;

    public function __construct()
    {
        $this->rekognitionClient = app('rekognitionClient');
        $this->transcribeClient = app('TranscribeServiceClient');
    }

    public static function maxOpenJobsExceeded(): bool
    {
        $currentOpenJobs = RekognitionTask::numberOfOpenJobs();
        $maxJobs = (int) config('rekognition.max_open_jobs', 20);

        return $currentOpenJobs >= $maxJobs;
    }

    public static function availableOpenJobs(): int
    {
        $currentOpenJobs = RekognitionTask::numberOfOpenJobs();
        $maxJobs = (int) config('rekognition.max_open_jobs', 20);

        if ($currentOpenJobs >= $maxJobs) {
            return 0;
        }

        return $maxJobs - $currentOpenJobs;
    }

    public static function shouldSkipDueToDuration(File $file, string $aiObject): bool
    {
        // 1. Allow TRANSCRIBES to process regardless of duration
        if ($aiObject === RekognitionTypes::TRANSCRIBES->value) {
            return false;
        }

        // 2. Check if the file is an audio, video, or GIF
        $isGif = $file->extension === 'gif';
        $isVideo = $file->type === FunctionsType::Video->value;
        $isAudio = $file->type === FunctionsType::Audio->value;

        // 3. Only apply duration check for audio, video, and GIF files
        if ($isGif || $isVideo || $isAudio) {
            $maxDuration = (int) config('manager.max_duration_millisecond');

            // Skip if duration is unknown (0) or exceeds the max duration
            return $file->duration === 0 || $file->duration > $maxDuration;
        }

        // 4. For other file types (e.g., images), do not skip
        return false;
    }

    public function process(File $file, string $aiObject = ''): bool
    {
        try {
            match ($aiObject) {
                RekognitionTypes::TEXTS->value       => $this->processTexts($file),
                RekognitionTypes::CELEBRITIES->value => $this->processCelebrities($file),
                RekognitionTypes::TRANSCRIBES->value => $this->processTranscribes($file),
                RekognitionTypes::SEGMENT->value     => $this->processSegment($file),
                default                              => throw new Exception('Unsupported Ai Object')
            };

            return true;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return false;
    }

    public function allAiProcessed(File $file): bool
    {
        $tasks = config('rekognition.ai_objects');

        foreach ($tasks as $task) {
            if (! AdminSetting::isRekognitionObjectEnabled($task)) {
                continue;
            }
            // check if there are any unprocessed tasks
            $unprocessedTasks = $file->rekognitionTasks()
                ->where('job_type', $task)
                ->where('analyzed', false)
                ->count();

            // check if the task does not exist
            $taskDontExist = $file->rekognitionTasks()
                ->where('job_type', $task)
                ->doesntExist();

            if ($unprocessedTasks > 0 || $taskDontExist) {
                return false;
            }
        }

        return true;
    }

    protected function foundSimilarFileAlreadyProcessed(Model|File $file, RekognitionTypes $objectType): bool
    {
        if ($file->md5 === null) {
            return false;
        }

        $similarFile = $file->similarFiles()
            ->whereHas('rekognitionTasks', fn ($query) => $query->where('job_type', $objectType)
                ->where('job_status', RekognitionJobStatus::SUCCEEDED)
            )
            ->first();

        if ($similarFile) {
            logger("Found similar file: {$similarFile->id} for object type: {$objectType->value}");

            // When replicating from a similar file, we should not carry over the AWS JobId.
            // Replicated results are terminal and should not be polled again.
            $rekognitionTask = $file->rekognitionTasks()->updateOrCreate(
                ['job_type' => $objectType],
                [
                    'service_name' => 'AWS',
                    'service_type' => config('rekognition.name'),
                    'job_id'       => null,
                    'job_status'   => RekognitionJobStatus::SUCCEEDED,
                    'analyzed'     => true,
                ]
            );

            // Copy the detection data from the similar file to this file
            $rekognitionTask->{"replicate{$objectType->value}"}($similarFile);

            return true;
        }

        return false;
    }

    public function saveJobTask(
        Model $file,
        RekognitionTypes $jobType,
        int|string|null $jobId = null,
        RekognitionJobStatus|string|null $status = null,
        array $result = [],
    ): ?RekognitionTask {
        $status = match (true) {
            $status instanceof RekognitionJobStatus => $status,
            is_string($status)                      => RekognitionJobStatus::tryFrom($status) ?? RekognitionJobStatus::IN_PROGRESS,
            default                                 => RekognitionJobStatus::IN_PROGRESS
        };

        $defaults = [
            'service_name' => 'AWS',
            'service_type' => config('rekognition.name'),
            'job_status'   => $status,
        ];

        if (filled($jobId)) {
            $defaults['job_id'] = trim((string) $jobId);
        }

        $rekognitionTask = $file->rekognitionTasks()->updateOrCreate([
            'file_id'  => $file->id,
            'job_type' => $jobType,
        ], $defaults);

        if ($status === RekognitionJobStatus::SUCCEEDED || $status === RekognitionJobStatus::COMPLETED) {
            $rekognitionTask->update(['analyzed' => true]);

            if (filled($result)) {
                $saved = $rekognitionTask->{"save{$jobType->value}"}($result);

                if (! $saved) {
                    $rekognitionTask->update(['job_status' => RekognitionJobStatus::FAILED]);
                }
            }
        }

        $rekognitionTask->save();

        return $rekognitionTask;
    }

    public function handleFailure(Model $file, RekognitionTypes $jobType, Exception|Throwable|null $exception = null, bool $fail = true): void
    {
        $status = $fail ? RekognitionJobStatus::FAILED : null;

        if ($exception && filled($exception)) {
            if (is_retryable_aws_error($exception)) {
                $status = null; // Do not fail
            }

            $this->log(
                text: "{$jobType->value} job has failed for file ({$file->id}) {$exception->getMessage()}",
                level: filled($status) ? 'error' : 'warning',
                context: [
                    'error' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString(),
                ]
            );
        }

        $this->saveJobTask($file, $jobType, null, $status);
    }

    public function saveTranscribeJobTask(
        Model $file,
        string $transcriptJobStatus,
        array $transcriptionArray,
    ): ?RekognitionTask {
        $status = RekognitionJobStatus::tryFrom($transcriptJobStatus) ?? RekognitionJobStatus::IN_PROGRESS;
        $done = in_array($status, [RekognitionJobStatus::SUCCEEDED, RekognitionJobStatus::COMPLETED], true);

        $rekognitionTask = $file->rekognitionTasks()->updateOrCreate([
            'file_id'  => $file->id,
            'job_type' => RekognitionTypes::TRANSCRIBES,
        ], [
            'service_name' => 'AWS',
            'service_type' => config('rekognition.name'),
            'job_status'   => $status,
            'analyzed'     => $done,
        ]);

        if (! $done) {
            return $rekognitionTask;
        }

        $rekognitionTask->saveTranscribes($transcriptionArray);

        return $rekognitionTask;
    }

    public function handleNoJobId(Model $file, RekognitionTypes $jobType): void
    {
        if ($file->type === FunctionsType::Image->value) {
            return;
        }

        $this->handleFailure($file, $jobType, null, false);
        $this->log(
            text: "Rekognition {$jobType->value} detection failed",
            level: 'error',
            icon: '❌',
            context: ['error' => 'JobId is empty']
        );
    }
}
