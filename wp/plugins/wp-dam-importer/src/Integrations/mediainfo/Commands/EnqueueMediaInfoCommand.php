<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediainfo\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\HorizonJobs;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use Illuminate\Support\Collection;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Mediainfo\Jobs\ProcessMediaInfoJob;

/** @deprecated (moved to nataero) */
class EnqueueMediaInfoCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:enqueue-media-info';

    protected $signature = self::SIGNATURE;

    protected $description = 'Gets the media-info data.';

    public function handle()
    {
        /** @var Collection<File> $files */
        $files = File::with(['mediainfoMetadata', 'operationStates'])
            ->whereNotNull('download_url')
            ->whereDoesntHave('operationStates', fn ($q) => $q
                ->where('operation_name', FileOperationName::MEDIAINFO)
            )
            ->limit(HorizonJobs::queueLimit('ffmpeg'))
            ->get();

        if ($files->isEmpty()) {
            $this->log('No unprocessed files found for ' . config('mediainfo.key'));

            return self::SUCCESS;
        }

        $this->log('Processing ' . config('mediainfo.key'), icon: '👀');

        foreach ($files as $file) {
            $file->markInitialized(
                FileOperationName::MEDIAINFO,
                'MediaInfo job initialized'
            );

            dispatch(new ProcessMediaInfoJob($file->id, auth()->id()));

            $this->log(config('mediainfo.key') . " PROCESSED FOR FILE ID ({$file->id})", icon: '✅');
        }

        return self::SUCCESS;
    }
}
