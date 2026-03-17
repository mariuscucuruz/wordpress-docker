<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Exif\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Traits\Loggable;

class MediaExifForgetCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:forget:exif';

    protected $signature = self::SIGNATURE
        . ' {--force : Clear AI even if the file is analyzed}'
        . ' {--serviceId= : ID of the specific service}'
        . ' {--fileId= : ID of the specific file}';

    protected $description = 'Rerun media exif.';

    public function handle(): int
    {
        $this->startLog();

        $serviceId = $this->option('serviceId');
        $force = $this->option('force');
        $fileId = $this->option('fileId');

        if ($fileId) {
            $file = File::findOrFail($fileId);
            $this->resetStatus($file);
            $this->endLog();

            return self::SUCCESS;
        }
        $files = File::query()
            ->where('service_id', $serviceId)
            ->with(['exifMetadata', 'operationStates'])
            ->whereNotNull('download_url')
            ->whereHas('operationStates', fn ($q) => $q
                ->where('operation_name', FileOperationName::EXIF)
                ->when(! $force, fn ($q) => $q->where('status', FileOperationStatus::FAILED))
            )
            ->cursor();
        $this->log("Files count: {$files->count()}");
        $files->each(fn ($file) => $this->resetStatus($file));

        $this->endLog();

        return self::SUCCESS;
    }

    private function resetStatus(File $file): void
    {
        $file
            ->operationStates()
            ->where('operation_name', FileOperationName::EXIF)
            ->delete();

        $file
            ->exifMetadata()
            ->delete();
    }
}
