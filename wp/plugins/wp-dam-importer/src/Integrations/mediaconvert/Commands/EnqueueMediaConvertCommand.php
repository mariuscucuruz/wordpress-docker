<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\HorizonJobs;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Jobs\DispatchMediaConvertJob;

class EnqueueMediaConvertCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:queue-media-convert';

    protected $signature = self::SIGNATURE . ' {--id= : The specific file ID}';

    protected $description = 'Sends videos to mediaconvert.';

    public function handle(): int
    {
        $this->startLog();

        $fileId = $this->option('id');

        if (filled($fileId)) {
            $file = File::find($fileId);

            if (! $file) {
                $this->log("File not found for ID {$fileId}", 'error');

                return self::INVALID;
            }

            return $this->convertFileByType($file);
        }

        $limit = HorizonJobs::queueLimit('long-running-task');

        $files = File::query()
            ->with(['user', 'service', 'operationStates'])
            ->whereNotNull('download_url')
            ->whereNull('view_url')
            ->where(fn (Builder $query) => $query
                ->whereIn('type', [FunctionsType::Video->value, FunctionsType::Audio->value])
                ->orWhere('extension', 'gif'))
            ->whereHas('operationStates', fn (Builder $query) => $query
                ->where('operation_name', FileOperationName::DOWNLOAD)
                ->where('status', FileOperationStatus::SUCCESS))
            ->whereDoesntHave('operationStates', fn (Builder $q) => $q
                ->where('operation_name', FileOperationName::CONVERT))
            ->limit($limit)
            ->cursor()
            ->each(fn ($file) => $this->convertFileByType($file));

        if ($files->isEmpty()) {
            $this->concludedLog('No more files to convert.');
        }

        return self::SUCCESS;
    }

    public function convertFileByType(File $file): int
    {
        if (! $file->shouldSendToMediaConvert()) {
            $this->log("File ID: {$file->id} cannot be sent to MediaConvert", 'warn');

            return self::INVALID;
        }

        $this->log("Queueing MediaConvert job for file {$file->id} ({$file->type})");

        $file->markInitialized(
            FileOperationName::CONVERT,
            'Sent to AWS MediaConvert for conversion'
        );

        dispatch(new DispatchMediaConvertJob($file));

        return self::SUCCESS;
    }
}
