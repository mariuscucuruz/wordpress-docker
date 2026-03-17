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
use MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Jobs\RetrieveMediaConvertJob;

class RetrieveMediaConvertCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:retrieve-media-convert';

    protected $signature = self::SIGNATURE . ' {--id= : Specific file ID to process}';

    protected $description = 'Retrieves AWS MediaConvert results for processing files based on FileOperationStates.';

    public function handle()
    {
        $this->startLog();

        $fileId = $this->option('id');

        if (filled($fileId)) {
            $file = File::find($fileId);

            if (! $file) {
                $this->log("File not found for ID {$fileId}", 'error');
                $this->endLog();

                return self::INVALID;
            }

            if (empty($file->download_url)) {
                $this->log("File with ID: {$fileId} isn't downloaded", 'warn');
                $this->endLog();

                return self::INVALID;
            }

            if (filled($file->view_url)) {
                $this->log("File with ID: {$fileId} is already processed for mediaconvert", 'warn');
                $this->endLog();

                return self::INVALID;
            }

            if ($file->operationStates()
                ->where('operation_name', FileOperationName::CONVERT)
                ->whereIn('status', [FileOperationStatus::SUCCESS, FileOperationStatus::PROCESSING])
                ->exists()
            ) {
                $this->log("File {$file->id} already has an active or successful CONVERT state", 'warn');
                $this->endLog();

                return self::INVALID;
            }

            $this->queueRetrieveJob($file);
            $this->endLog();

            return self::SUCCESS;
        }

        $limit = HorizonJobs::queueLimit('long-running-task');

        $files = File::query()
            ->with('operationStates')
            ->whereNotNull('download_url')
            ->whereNull('view_url')
            ->where(fn (Builder $query) => $query
                ->whereIn('type', [FunctionsType::Video->value, FunctionsType::Audio->value])
                ->orWhere('extension', 'gif'))
            ->whereHas('operationStates', fn (Builder $q) => $q
                ->where('operation_name', FileOperationName::CONVERT)
                ->where('status', FileOperationStatus::PROCESSING))
            ->oldest()
            ->limit($limit)
            ->cursor()
            ->each(fn ($file) => $this->queueRetrieveJob($file));

        if ($files->isEmpty()) {
            $this->log('No unprocessed files found for Mediaconvert', 'warn');
            $this->endLog();

            return self::SUCCESS;
        }

        $this->log('Processing Mediaconvert for file(s)');
        $this->endLog();

        return self::SUCCESS;
    }

    private function queueRetrieveJob(File $file): void
    {
        $this->log("Dispatching RetrieveMediaConvertJob for file {$file->id}");

        $file->markProcessing(
            FileOperationName::CONVERT,
            'Retrieving MediaConvert results'
        );

        dispatch(new RetrieveMediaConvertJob($file));
    }
}
