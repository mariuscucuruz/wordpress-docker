<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Exif\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\HorizonJobs;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Exif\Jobs\ProcessMediaExifJob;

/** @deprecated (moved to nataero) */
class EnqueueMediaExifCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:enqueue-exif';

    protected $signature = self::SIGNATURE;

    protected $description = 'Dispatch media exif jobs.';

    public function handle()
    {
        $files = File::query()
            ->with(['exifMetadata', 'operationStates'])
            ->whereNotNull('download_url')
            ->whereDoesntHave('operationStates', fn ($q) => $q
                ->where('operation_name', FileOperationName::EXIF)
            )
            ->limit(HorizonJobs::queueLimit('exiftool'))
            ->get();

        if ($files->isEmpty()) {
            $this->log('No unprocessed files found for ' . config('exif.key'));

            return self::SUCCESS;
        }

        $this->log('Processing ' . config('exif.key'), icon: '👀');

        foreach ($files as $file) {
            $file->markInitialized(
                FileOperationName::EXIF,
                'Exif job initialized'
            );

            dispatch(new ProcessMediaExifJob($file, auth()->id()));

            $this->log(config('exif.key') . " PROCESSED FOR FILE ID ({$file->id})", icon: '✅');
        }

        return self::SUCCESS;
    }
}
