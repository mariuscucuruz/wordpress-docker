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
use MariusCucuruz\DAMImporter\Integrations\Nataero\Jobs\NataeroMediainfoDispatchJob;

class NataeroDispatchMediainfo extends Command
{
    use Loggable;

    public const string SIGNATURE = 'nataero:dispatch:mediainfo';

    public int $dispatchCount = 0;

    protected $signature = self::SIGNATURE
        . ' {--fileID=}'
        . ' {--teamID=}'
        . ' {--serviceID=}'
        . ' {--forceOverwrite}'
        . ' {--audio}'
        . ' {--force}'
        . ' {--limit=}';

    protected $description = 'Enqueue asset mediainfo through the Nataero API.';

    private bool $forceOverwrite;

    public function handle(NataeroDispatcher $nataeroDispatcher): int
    {
        $this->startLog();

        if ($this->option('fileID')) {
            $file = File::findOrFail($this->option('fileID'));
            $nataeroDispatcher(
                $file,
                FileOperationName::MEDIAINFO,
                NataeroFunctionType::MEDIAINFO,
                NataeroMediainfoDispatchJob::class
            );
            $this->endLog();

            return self::SUCCESS;
        }

        $this->forceOverwrite = (bool) $this->option('forceOverwrite');

        /** @var Collection<File> $files */
        $files = File::with(['mediainfoMetadata', 'operationStates'])
            ->whereNotNull('download_url')
            ->whereDoesntHave('operationStates', fn ($q) => $q
                ->where('operation_name', FileOperationName::MEDIAINFO)
            )
            ->whereDoesntHave('nataeroTasks', fn ($q) => $q
                ->where('function_type', strtoupper(NataeroFunctionType::MEDIAINFO->value))
            )
            ->limit($nataeroDispatcher->parseNumber($this->option('limit')))
            ->get();

        if ($files->isEmpty()) {
            $this->log('No unprocessed files found for ' . config('mediainfo.key'));

            return self::SUCCESS;
        }

        $this->log('Processing ' . config('mediainfo.key'), icon: '👀');

        foreach ($files as $file) {
            $nataeroDispatcher(
                $file,
                FileOperationName::MEDIAINFO,
                NataeroFunctionType::MEDIAINFO,
                NataeroMediainfoDispatchJob::class
            );
            $this->dispatchCount++;
        }

        $this->endLog();

        return self::SUCCESS;
    }
}
