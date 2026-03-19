<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Jobs;

use Exception;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Models\FileOperationState;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Services\AcrCloudService;

class RetrieveAcrCloudJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Loggable, Queueable;

    public $timeout = 3600;

    public $tries = 1;

    public function __construct(public FileOperationState $fileOperationState)
    {
        $this->onQueue(QueueRouter::route('api'));
    }

    public function handle(): void
    {
        if (empty($this->fileOperationState->remote_task_id)) {
            $this->fileOperationState->file->markFailure(
                FileOperationName::ACRCLOUD,
                'ACRCloud operation state does not have a remote_task_id',
            );

            $this->log(
                'RetrieveAcrCloudJob: Missing fileOperationState data/remote_task_id, marking operation as failed.',
                'error',
                null,
                [
                    'state_id'       => $this->fileOperationState->id,
                    'file_id'        => $this->fileOperationState->file_id,
                    'remote_task_id' => $this->fileOperationState->remote_task_id,
                ]
            );

            return;
        }

        $result = AcrCloudService::make()->getFileResult($this->fileOperationState);

        $this->fileOperationState->updateStatus(
            $result ? FileOperationStatus::SUCCESS : FileOperationStatus::FAILED,
            $result
                ? 'ACRCloud result successfully retrieved'
                : 'ACRCloud result retrieval failed'
        );
    }

    public function failed(Exception $exception): void
    {
        $this->log($exception->getMessage(), 'error', null, $exception->getTrace());

        $this->file->markFailure(
            FileOperationName::ACRCLOUD,
            'RetrieveAcrCloudJob failed',
            $exception->getMessage()
        );
    }

    public function uniqueId(): string
    {
        return class_basename($this) . '_' . $this->fileOperationState->file_id;
    }
}
