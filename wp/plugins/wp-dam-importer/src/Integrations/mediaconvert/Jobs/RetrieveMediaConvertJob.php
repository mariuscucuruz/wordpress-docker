<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Jobs;

use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Models\FileOperationState;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Templates\DefaultSettings;
use MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Enums\MediaconvertJobStatus;
use MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Mediaconvert as MediaconvertService;

class RetrieveMediaConvertJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Loggable, Queueable, SerializesModels;

    public $timeout = 3600;

    public $tries = 1;

    public function __construct(public File $file)
    {
        $this->onQueue(QueueRouter::route('long-running-task'));
    }

    public function handle(MediaconvertService $mediaconvert): void
    {
        $operation = $this->file->mediaconvertOperation;

        if (! $operation) {
            $this->log("No CONVERT operation found for file {$this->file->id}", 'warn');

            return;
        }

        $remoteJobId = $operation->remote_task_id;

        if (empty($remoteJobId)) {
            $this->log("Missing MediaConvert job ID for file {$this->file->id}", 'error');

            return;
        }

        $this->log("Checking MediaConvert job: {$remoteJobId}", icon: '👀');

        rescue(fn () => retry(
            times: config('rekognition.retry_on_failure', 3),
            callback: function () use ($mediaconvert, $remoteJobId, $operation) {
                $data = $mediaconvert->getJobById($remoteJobId);

                if ($data) {
                    $jobStatus = is_object($data) && method_exists($data, 'search')
                        ? $data->search('Job.Status')
                        : data_get($data, 'Job.Status');

                    if ($jobStatus) {
                        $this->updateOperationState($operation, (string) $jobStatus, $data);
                    }
                }
            },
            sleepMilliseconds: fn ($attempt) => (2 ** $attempt) * config('mediaconvert.retry_in_milliseconds'),
            when: fn ($e) => is_retryable_aws_error($e)
        ),
            fn ($e) => $this->updateOperationState($operation, MediaconvertJobStatus::ERROR->value)
        );
    }

    public function uniqueId(): string
    {
        return class_basename($this) . '_' . $this->file->id;
    }

    private function updateOperationState(FileOperationState $operation, string $jobStatus): void
    {
        $url = null;
        $status = FileOperationStatus::PROCESSING;
        $message = "MediaConvert job status: {$jobStatus}";

        if ($jobStatus === MediaconvertJobStatus::COMPLETE->value) {
            $url = DefaultSettings::defaultFilePath($this->file);
            $status = FileOperationStatus::SUCCESS;
            $this->file->importGroup?->increment('number_of_files_processed');
            $this->file->importGroup?->parent?->increment('number_of_files_processed');
        }

        if ($jobStatus === MediaconvertJobStatus::ERROR->value) {
            $status = FileOperationStatus::FAILED;
        }

        $data = array_merge($operation->data ?? [], ['job_status' => $jobStatus]);

        if (filled($url)) {
            $data['url'] = $url;
            $this->file->update(['view_url' => $url]);
        }

        $operation->update([
            'status'     => $status,
            'message'    => $message,
            'data'       => $data,
            'updated_at' => now(),
        ]);

        $this->logJobStatus($jobStatus);
    }

    private function logJobStatus(string $jobStatus): void
    {
        $icon = match ($jobStatus) {
            MediaconvertJobStatus::COMPLETE->value => '✅',
            MediaconvertJobStatus::ERROR->value    => '❌',
            default                                => '⏳'
        };

        $this->log("File ID ({$this->file->id}) MediaConvert is {$jobStatus}", icon: $icon);
    }

    public function failed(Throwable $exception): void
    {
        $this->log('Retrieve MediaConvert failed', 'error', null, [
            'file_id'   => $this->file->id,
            'exception' => $exception,
        ]);
    }
}
