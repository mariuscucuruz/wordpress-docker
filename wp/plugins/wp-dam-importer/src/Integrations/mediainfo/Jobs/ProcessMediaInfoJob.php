<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediainfo\Jobs;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Integrations\Mediainfo\Mediainfo;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use MariusCucuruz\DAMImporter\Enums\FunctionStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class ProcessMediaInfoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;

    public $tries = 1;

    public $uniqueFor = 3600;

    protected File $file;

    public function __construct(public string $fileId, public string|int|null $userId = null, public ?string $tmpFile = null)
    {
        $this->onQueue(QueueRouter::route('mediainfo'));

        $this->file = File::findOrFail($this->fileId);
    }

    public function handle(Mediainfo $mediainfo)
    {
        $this->file->markProcessing(
            FileOperationName::MEDIAINFO,
            'Started processing'
        );

        $statusValue = $mediainfo->process($this->file) ? FunctionStatus::SUCCEEDED : FunctionStatus::FAILED;

        $this->file->markOperation(
            FileOperationName::MEDIAINFO,
            $statusValue === FunctionStatus::SUCCEEDED
                ? FileOperationStatus::SUCCESS
                : FileOperationStatus::FAILED,
            $statusValue === FunctionStatus::SUCCEEDED
                ? 'MediaInfo extraction complete'
                : 'MediaInfo failed',
        );
    }

    public function failed($exception)
    {
        $this->file->markFailure(
            FileOperationName::MEDIAINFO,
            $exception->getMessage(),
            $exception->getTraceAsString()
        );

        $msg = $exception->getMessage() ?: get_class($exception);

        logger()->error(__CLASS__ . " failed for file {$this->file->id}: {$msg}", [
            'file_id'   => $this->file->id,
            'exception' => $exception,
        ]);
    }

    public function uniqueId(): string
    {
        return class_basename($this) . '_' . $this->file->id;
    }
}
