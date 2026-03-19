<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sneakpeek\Jobs;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Integrations\Sneakpeek\Sneakpeek;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use MariusCucuruz\DAMImporter\Enums\FunctionStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class SneakpeekJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Loggable, Queueable;

    public $timeout = 3600;

    public $tries = 1;

    public $uniqueFor = 3600;

    public function __construct(public File $file, public string|int|null $userId = null, public ?string $tmpFile = null)
    {
        $this->onQueue(QueueRouter::route('ffmpeg'));
    }

    public function handle(Sneakpeek $sneakpeek)
    {
        $this->file->markProcessing(
            FileOperationName::SNEAKPEEK,
            'Started sneakpeek job'
        );

        $statusValue = $sneakpeek->process($this->file) ? FunctionStatus::SUCCEEDED : FunctionStatus::FAILED;

        $this->file->markOperation(
            FileOperationName::SNEAKPEEK,
            $statusValue === FunctionStatus::SUCCEEDED ? FileOperationStatus::SUCCESS : FileOperationStatus::FAILED,
            $statusValue === FunctionStatus::SUCCEEDED ? 'Sneakpeek Succeeded' : 'Sneakpeek Failed',
        );

        if ($statusValue === FunctionStatus::SUCCEEDED) {
            $this->file->refresh();
            $this->file->searchable();
        }
    }

    public function failed(Exception $exception)
    {
        $this->file->markFailure(
            FileOperationName::SNEAKPEEK,
            'Sneakpeek job failed',
            $exception->getMessage()
        );
        $this->log(
            text: 'Sneakpeek Job Failed',
            level: 'error',
            icon: '❌',
            context: [
                'file_id'   => $this->file->id,
                'exception' => $exception,
            ]
        );
    }

    public function uniqueId(): string
    {
        return class_basename($this) . '_' . $this->file->id;
    }
}
