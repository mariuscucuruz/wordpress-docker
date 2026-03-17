<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use Illuminate\Support\Collection;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Actions\NataeroDispatcher;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Jobs\NataeroExifDispatchJob;

class NataeroDispatchExif extends Command
{
    use Loggable;

    public const string SIGNATURE = 'nataero:dispatch:exif';

    public int $dispatchCount = 0;

    protected $signature = self::SIGNATURE
        . ' {--fileID=}'
        . ' {--teamID=}'
        . ' {--serviceID=}'
        . ' {--forceOverwrite}'
        . ' {--audio}'
        . ' {--force}'
        . ' {--limit=}';

    protected $description = 'Enqueue asset exif through the Nataero API.';

    private bool $forceOverwrite;

    public function handle(NataeroDispatcher $nataeroDispatcher): int
    {
        $this->startLog();

        if ($this->option('fileID')) {
            $file = File::findOrFail($this->option('fileID'));
            $nataeroDispatcher(
                $file,
                FileOperationName::EXIF,
                NataeroFunctionType::EXIF,
                NataeroExifDispatchJob::class
            );
            $this->endLog();

            return self::SUCCESS;
        }

        $this->forceOverwrite = (bool) $this->option('forceOverwrite');

        /** @var Collection<File> $files */
        $files = File::with(['exifMetadata', 'operationStates'])
            ->whereNotNull('download_url')
            ->whereDoesntHave('operationStates', fn ($q) => $q
                ->where('operation_name', FileOperationName::EXIF)
            )
            ->whereDoesntHave('nataeroTasks', fn ($q) => $q
                ->where('function_type', strtoupper(NataeroFunctionType::EXIF->value))
            )
            ->limit($nataeroDispatcher->parseNumber($this->option('limit')))
            ->get();

        if ($files->isEmpty()) {
            $this->log('No unprocessed files found for exif');

            return self::SUCCESS;
        }

        $this->log('Processing exif', icon: '👀');

        foreach ($files as $file) {
            $nataeroDispatcher(
                $file,
                FileOperationName::EXIF,
                NataeroFunctionType::EXIF,
                NataeroExifDispatchJob::class
            );
        }

        $this->endLog();

        return self::SUCCESS;
    }
}
