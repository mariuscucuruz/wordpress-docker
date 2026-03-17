<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Jobs;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Rekognition;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use MariusCucuruz\DAMImporter\Traits\UploadsData;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use MariusCucuruz\DAMImporter\Interfaces\CanUpload;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionTypes;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionJobStatus;

class RetrieveRekognitionJob implements CanUpload, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Loggable, Queueable, SerializesModels, UploadsData;

    public $timeout = 3600;

    public $tries = 1;

    protected Rekognition $rekognition;

    protected array $relationship;

    protected int $aiFailureCount = 0;

    public function __construct(public File $file)
    {
        $this->onQueue(QueueRouter::route('api'));
    }

    public function handle(Rekognition $rekognition): void
    {
        $this->startLog();

        $type = strtoupper($this->file->type) ?? 'VIDEO';

        $this->log("Getting {$type} Rekognition for file ID ({$this->file->id})");

        $this->rekognition = $rekognition;

        $this->file->load('rekognitionTasks'); // MUST be eager loaded.

        try {
            match ($this->file->type) {
                'audio' => $this->handleAudioFile(), // transcription only
                default => $this->handleVideoFile()
            };
        } catch (Exception $e) {
            $this->log(__FUNCTION__ . " error on file ID ({$this->file->id}): {$e->getMessage()}", 'error');

            throw $e;
        }
    }

    public function handleVideoFile(): void
    {
        $this->processAi(RekognitionTypes::TEXTS);
        $this->processAi(RekognitionTypes::SEGMENT);
        $this->processAi(RekognitionTypes::CELEBRITIES);
        $this->processTranscription();

        if ($this->rekognition->allAiProcessed($this->file)) {
            $this->concludedLog('VIDEO ANALYZED');
        } elseif ($this->aiFailureCount === count(config('rekognition.ai_objects')) - 1) {
            $this->log('All AI failed', 'error');
        }

        $this->endLog();
    }

    public function handleAudioFile(): void
    {
        $this->processTranscription();

        if ($this->rekognition->allAiProcessed($this->file)) {
            $this->concludedLog('AUDIO ANALYZED');
        } elseif ($this->aiFailureCount === count(config('rekognition.ai_objects'))) {
            $this->log('All AI failed for audio file', 'error');
        }
    }

    public function uniqueId(): string
    {
        return class_basename($this) . '_' . $this->file->id;
    }

    public function processTranscription(): void
    {
        $transcribeTask = $this->file->rekognitionTasks()
            ->where('job_type', RekognitionTypes::TRANSCRIBES)
            ->where('analyzed', false)
            ->latest()
            ->first();

        // Additional check for 'transcribe' since it has a different structure:
        if (filled($transcribeTask?->job_id)) {
            $transcribeData = $this->rekognition->getTranscribeById($transcribeTask->job_id);

            if (! $transcribeData) {
                $this->rekognition->handleFailure($this->file, RekognitionTypes::TRANSCRIBES);
                $this->aiFailureCount++;

                return;
            }

            $transcriptJobStatus = $transcribeData->search('TranscriptionJob.TranscriptionJobStatus');

            $this->logJob(
                strtoupper(RekognitionTypes::TRANSCRIBES->value) . ' DETECTION',
                $transcriptJobStatus,
                $this->file->id
            );

            $transcriptionArray = $this->transcripts($transcriptJobStatus, $transcribeData);

            $this->rekognition->saveTranscribeJobTask(
                $this->file,
                $transcriptJobStatus,
                $transcriptionArray
            );

            $this->uploadData(
                $this->file,
                $transcriptionArray,
                config('rekognition.name') . '-' . RekognitionTypes::TRANSCRIBES->value
            );
        }
    }

    private function processAi(RekognitionTypes $jobType): void
    {
        $method = $jobType->value;

        if (! method_exists($this, $method)
            || ! in_array($method, config('rekognition.ai_objects', []), true)) {
            return;
        }

        $titleTaskName = str($method)->singular()->title()->toString();
        $jobFunction = "get{$titleTaskName}DetectionById"; // getLabelDetectionById
        $uploadDataKey = config('rekognition.name') . '-' . $method;

        $rekognitionTask = $this->file->rekognitionTasks()
            ->where('job_type', $jobType)
            ->where('analyzed', false)
            ->latest()
            ->first();

        if (! $rekognitionTask) {
            return;
        }

        // Skip polling if the task is already analyzed or in a terminal state
        if ($rekognitionTask->analyzed === true
            || in_array($rekognitionTask->job_status, [
                RekognitionJobStatus::SUCCEEDED,
                RekognitionJobStatus::COMPLETED,
            ], true)) {
            return;
        }

        if (empty($rekognitionTask->job_id) || (string) $rekognitionTask->job_id === '0') {
            return;
        }

        $jobId = $rekognitionTask->job_id;

        $data = $this->rekognition->{$jobFunction}($jobId);

        if (empty($data)) {
            $this->rekognition->handleFailure($this->file, $jobType);
            $this->aiFailureCount++;

            return;
        }

        $jobStatus = $data->get('JobStatus');

        if ($jobStatus === RekognitionJobStatus::FAILED->value) {
            $this->rekognition->handleFailure($this->file, $jobType);
            $this->aiFailureCount++;

            return;
        }

        $this->logJob(
            strtoupper($method) . ' DETECTION',
            $jobStatus,
            $this->file->id
        );

        $elements = iterator_to_array(
            /** @phpstan-ignore-next-line */
            $this->{$method}($this->rekognition, $jobId),
            false // NOTE: preserve_keys must be false!
        );

        $this->rekognition->saveJobTask($this->file, $jobType, $jobId, $jobStatus, $elements);

        $this->uploadData($this->file, $elements, $uploadDataKey);
    }

    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    private function labels($rekognition, $jobId)
    {
        $nextToken = null;
        do {
            $response = $rekognition->getLabelDetectionById($jobId, $nextToken);

            if (! $response) {
                $this->log(__FUNCTION__ . ' detection is failed for job ID: ' . $jobId, 'error');
                yield [];

                continue;
            }
            $labels = $response->get('Labels');
            $nextToken = $response->get('NextToken');

            yield from $labels;
        } while (! empty($nextToken));
    }

    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    private function faces($rekognition, $jobId)
    {
        $nextToken = null;
        do {
            $response = $rekognition->getFaceDetectionById($jobId, $nextToken);

            if (! $response) {
                $this->log(__FUNCTION__ . ' detection is failed for job ID: ' . $jobId, 'error');
                yield [];

                continue;
            }
            $faces = $response->get('Faces');
            $nextToken = $response->get('NextToken');

            yield from $faces;
        } while (! empty($nextToken));
    }

    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    private function texts($rekognition, $jobId)
    {
        $nextToken = null;
        do {
            $response = $rekognition->getTextDetectionById($jobId, $nextToken);

            if (! $response) {
                $this->log(__FUNCTION__ . ' detection is failed for job ID: ' . $jobId, 'error');
                yield [];

                continue;
            }
            $texts = $response->get('TextDetections');
            $nextToken = $response->get('NextToken');

            yield from $texts;
        } while (! empty($nextToken));
    }

    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    private function segments(Rekognition $rekognition, $jobId)
    {
        $nextToken = null;
        do {
            $response = $rekognition->getSegmentDetectionById($jobId, $nextToken);

            if (! $response) {
                $this->log(__FUNCTION__ . ' detection is failed for job ID: ' . $jobId, 'error');
                yield [];

                continue;
            }

            $segments = $response->get('Segments');
            $nextToken = $response->get('NextToken');
            yield from $segments;
        } while (! empty($nextToken));
    }

    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    private function moderations($rekognition, $jobId)
    {
        $nextToken = null;
        do {
            $response = $rekognition->getModerationDetectionById($jobId, $nextToken);

            if (! $response) {
                $this->log(__FUNCTION__ . ' detection is failed for job ID: ' . $jobId, 'error');
                yield [];

                continue;
            }
            $moderations = $response->get('ModerationLabels');
            $nextToken = $response->get('NextToken');

            yield from $moderations;
        } while (! empty($nextToken));
    }

    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    private function celebrities(Rekognition $rekognition, $jobId)
    {
        $nextToken = null;
        do {
            $response = $rekognition->getCelebrityDetectionById($jobId, $nextToken);

            if (! $response) {
                $this->log(__FUNCTION__ . ' detection is failed for job ID: ' . $jobId, 'error');
                yield [];

                continue;
            }
            $celebrity = $response->get('Celebrities');
            $nextToken = $response->get('NextToken');

            yield from $celebrity;
        } while (! empty($nextToken));
    }

    /**
     * Called dynamically.
     * Don't remove or change the method name.
     */
    private function transcripts($jobStatus, $data): array
    {
        $transcriptUrl = $data->search('TranscriptionJob.Transcript.TranscriptFileUri');

        if (empty($transcriptUrl)) {
            $this->log('No transcript found for rekognition task', 'warn');

            return [];
        }

        $parsedUrl = parse_url($transcriptUrl);
        $path = data_get($parsedUrl, 'path');

        if (empty($path)) {
            $this->log('Invalid transcript URL structure', 'error');

            return [];
        }

        $presignedUrl = presigned_url(basename($path));

        if (empty($presignedUrl)) {
            $this->log('Failed to generate pre-signed URL for transcript file', 'error');

            return [];
        }

        $transcripts = '';
        $transcriptData = $data->search('TranscriptionJob.Transcript');

        if ($jobStatus !== RekognitionJobStatus::FAILED->value && $transcriptData) {
            $stream = fopen($presignedUrl, 'rb');

            while ($chunk = fread($stream, 1024)) {
                $transcripts .= $chunk;
            }
            fclose($stream);
        }

        return json_decode($transcripts ?: '[]', true);
    }

    private function logJob($type, $status, $fileId)
    {
        $icon = match ($status) {
            RekognitionJobStatus::IN_PROGRESS->value => '⏳',
            RekognitionJobStatus::SUCCEEDED->value,
            RekognitionJobStatus::COMPLETED->value => '🟢',
            RekognitionJobStatus::FAILED->value    => '🔴',
            default                                => '🟠',
        };
        $this->log("File ID: ({$fileId}): {$type} is ({$status})", icon: $icon);
    }
}
