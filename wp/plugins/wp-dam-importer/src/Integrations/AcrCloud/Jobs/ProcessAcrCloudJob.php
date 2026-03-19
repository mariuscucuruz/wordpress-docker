<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Jobs;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\AcrCloud;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotCompleteFunction;

class ProcessAcrCloudJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Loggable, Queueable;

    public $timeout = 3600;

    public $tries = 1;

    public function __construct(public File $file)
    {
        $this->onQueue(QueueRouter::route('long-running-task'));
    }

    /**
     * @throws CouldNotCompleteFunction
     */
    public function handle(AcrCloud $acrCloud): void
    {
        if (! FunctionsType::acrCanProcess($this->file->type)) {
            throw new CouldNotCompleteFunction("Cannot process {$this->file->type} files.");
        }

        $success = $acrCloud->process($this->file);

        $this->file->markOperation(
            FileOperationName::ACRCLOUD,
            $success ? FileOperationStatus::PROCESSING : FileOperationStatus::FAILED,
            $success ? 'ACRCloud processing successfully queued' : 'ACRCloud processing failed to send off'
        );
    }

    public function failed(Exception $exception): void
    {
        $this->log(
            text: 'Process Acr Cloud Job Failed',
            level: 'error',
            icon: '❌',
            context: [
                'file_id'   => $this->file->id,
                'exception' => $exception,
            ]
        );

        $this->file->markFailure(
            FileOperationName::ACRCLOUD,
            'ACRCloud Processing failed',
            $exception->getMessage()
        );
    }

    public function uniqueId(): string
    {
        return class_basename($this) . '_' . $this->file->id;
    }
}
