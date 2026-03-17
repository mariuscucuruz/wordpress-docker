<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Exif\Jobs;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Integrations\Exif\Exif;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Models\FileOperationState;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use MariusCucuruz\DAMImporter\Enums\FunctionStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class ProcessMediaExifJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 3600;

    public $tries = 1;

    public $uniqueFor = 3600;

    public function __construct(public File $file, public string|int|null $userId = null, public ?string $tmpFile = null)
    {
        $this->onQueue(QueueRouter::route('exiftool'));
    }

    public function handle(Exif $exif)
    {
        FileOperationState::updateOrCreate([
            'file_id'        => $this->file->id,
            'operation_name' => FileOperationName::EXIF,
        ], [
            'status'  => FileOperationStatus::PROCESSING,
            'message' => 'Started processing',
        ]);

        try {
            $statusValue = $exif->process($this->file) ? FunctionStatus::SUCCEEDED : FunctionStatus::FAILED;
        } catch (Exception $e) {
            logger()->error("Error processing EXIF data for file ID {$this->file->id}: {$e->getMessage()}");
            $statusValue = FunctionStatus::FAILED;
        }

        FileOperationState::updateOrCreate([
            'file_id'        => $this->file->id,
            'operation_name' => FileOperationName::EXIF,
        ], [
            'status' => $statusValue === FunctionStatus::SUCCEEDED
                ? FileOperationStatus::SUCCESS
                : FileOperationStatus::FAILED,
            'message' => $statusValue === FunctionStatus::SUCCEEDED
                ? 'Exif extraction complete'
                : 'Exif failed',
        ]);
    }

    public function failed(Exception $exception)
    {
        $this->file->markFailure(
            FileOperationName::EXIF,
            'Exif extraction failed',
            $exception->getMessage()
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
