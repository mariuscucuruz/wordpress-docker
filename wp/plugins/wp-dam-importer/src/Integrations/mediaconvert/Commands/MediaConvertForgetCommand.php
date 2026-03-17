<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;

class MediaConvertForgetCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:forget:mediaconvert';

    protected $signature = self::SIGNATURE
        . ' {--force : Reset even successful conversions}'
        . ' {--serviceId= : ID of a specific service}'
        . ' {--fileId= : ID of a specific file}';

    protected $description = 'Resets MediaConvert operation states to allow reconversion.';

    public function handle(): int
    {
        $this->startLog();

        $serviceId = $this->option('serviceId');
        $force = $this->option('force');
        $fileId = $this->option('fileId');

        if ($fileId) {
            $file = File::findOrFail($fileId);
            $this->resetOperationState($file);
            $this->endLog();

            return self::SUCCESS;
        }

        $files = File::query()
            ->when($serviceId, fn (Builder $query) => $query->where('service_id', $serviceId))
            ->where('type', FunctionsType::Video)
            ->whereHas('operationStates', function (Builder $query) use ($force) {
                $query->where('operation_name', FileOperationName::CONVERT)
                    ->whereIn('status',
                        $force
                            ? [FileOperationStatus::SUCCESS, FileOperationStatus::FAILED]
                            : [FileOperationStatus::FAILED]);
            })
            ->cursor();

        $this->log("Files count: {$files->count()}");
        $files->each(fn ($file) => $this->resetOperationState($file));

        $this->endLog();

        return self::SUCCESS;
    }

    private function resetOperationState(File $file): void
    {
        $this->log("Resetting MediaConvert state for file ID {$file->id}");

        $file->operationStates()->where('operation_name', FileOperationName::CONVERT)->delete();

        $file->update(['view_url' => null]);

        $this->concludedLog("File {$file->id} marked for reconversion.", '♻️');
    }
}
