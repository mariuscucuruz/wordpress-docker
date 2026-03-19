<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sneakpeek\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\HorizonJobs;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Sneakpeek\Jobs\SneakpeekJob;

/** @deprecated replaced by NataeroDispatchSneakpeek */
class MediaSneakpeek extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:sneakpeek';

    protected $signature = self::SIGNATURE;

    protected $description = 'Generates thumbnails for sneakpeeks.';

    public function handle(): int
    {
        $this->startLog();

        $files = File::with(['sneakpeeks', 'operationStates'])
            ->whereNotNull('download_url')
            ->whereNotNull('view_url')
            ->where('type', 'video')
            ->whereDoesntHave('operationStates', fn ($q) => $q
                ->where('operation_name', FileOperationName::SNEAKPEEK)
            )
            ->limit(HorizonJobs::queueLimit('ffmpeg'))
            ->cursor()
            ->each(function ($file) {
                $file->markInitialized(
                    FileOperationName::SNEAKPEEK,
                    'Sneakpeek job initialized'
                );

                $this->log('Processing Sneakpeek', icon: '👀');

                dispatch(new SneakpeekJob($file));

                $this->log("Sneakpeek processed for file ID: ({$file->id})");
            });

        if ($files->isEmpty()) {
            $this->log('No files left to process for Sneakpeek', 'warn');
        }

        $this->endLog();

        return self::SUCCESS;
    }
}
