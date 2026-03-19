<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;

class ForgetAcrCloudCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:forget:acrcloud';

    protected $signature = self::SIGNATURE
        . ' {--force : Clear AI even if the file is analyzed}'
        . ' {--serviceId= : ID of the specific service}'
        . ' {--fileId= : ID of the specific file}';

    protected $description = 'Command to clear music detection results.';

    public function handle(): int
    {
        $this->startLog();

        $serviceId = $this->option('serviceId');
        $force = $this->option('force');
        $fileId = $this->option('fileId');

        if ($fileId) {
            $files = File::findOrFail($fileId);
            $this->resetStatus($files);
            $this->endLog();

            return self::SUCCESS;
        }

        $filesQuery = File::where('service_id', $serviceId)
            ->with('operationStates')
            ->whereNotNull('download_url')
            ->whereNotNull('view_url')
            ->where('type', FunctionsType::Audio)
            ->whereHas('operationStates', function ($query) use ($force) {
                $query->where('operation_name', FileOperationName::ACRCLOUD);

                if (! $force) {
                    $query->whereIn('status', [
                        FileOperationStatus::PROCESSING,
                        FileOperationStatus::FAILED,
                    ]);
                }
            })
            ->cursor();

        if ($filesQuery->isEmpty()) {
            $this->log('No pending files found.');
            $this->endLog();

            return self::SUCCESS;
        }

        $filesQuery->each(function ($file) {
            $this->resetStatus($file);
        });

        $this->endLog();

        return self::SUCCESS;
    }

    private function resetStatus(File $file): void
    {
        $file->acrCloudMusicTracks()->delete();
        $file->operationStates()->where('operation_name', FileOperationName::ACRCLOUD)->delete();

        $this->log(config('acrcloud.key', 'ACRCLOUD') . " CLEARED MUSIC TRACKS FOR FILE ID ({$file->id})", icon: '🗑');
    }
}
