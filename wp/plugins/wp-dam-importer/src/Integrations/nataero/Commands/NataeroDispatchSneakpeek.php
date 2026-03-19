<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Actions\NataeroDispatcher;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Jobs\NataeroSneakpeekDispatchJob;

class NataeroDispatchSneakpeek extends Command
{
    use Loggable;

    public const string SIGNATURE = 'nataero:dispatch:sneakpeek';

    public int $dispatchCount = 0;

    protected $signature = self::SIGNATURE
        . ' {--fileID=}'
        . ' {--teamID=}'
        . ' {--serviceID=}'
        . ' {--forceOverwrite}'
        . ' {--audio}'
        . ' {--force}'
        . ' {--limit=}';

    protected $description = 'Enqueue asset sneakpeek through the Nataero API.';

    private bool $forceOverwrite;

    public function handle(NataeroDispatcher $nataeroDispatcher): int
    {
        $this->startLog();

        if ($this->option('fileID')) {
            $file = File::findOrFail($this->option('fileID'));
            $nataeroDispatcher(
                $file,
                FileOperationName::SNEAKPEEK,
                NataeroFunctionType::SNEAKPEEK,
                NataeroSneakpeekDispatchJob::class
            );
            $this->endLog();

            return self::SUCCESS;
        }

        $this->forceOverwrite = (bool) $this->option('forceOverwrite');

        $files = File::with(['sneakpeeks', 'operationStates'])
            ->whereNotNull('download_url')
            ->whereNotNull('view_url')
            ->where('type', 'video')
            ->whereDoesntHave('operationStates', fn ($q) => $q
                ->where('operation_name', FileOperationName::SNEAKPEEK)
            )
            ->whereDoesntHave('nataeroTasks', fn ($q) => $q
                ->where('function_type', strtoupper(NataeroFunctionType::SNEAKPEEK->value))
            )
            ->limit($nataeroDispatcher->parseNumber($this->option('limit')))
            ->get();

        if ($files->isEmpty()) {
            $this->log('No unprocessed files found for sneakpeek.', icon: 'ℹ️');

            return self::SUCCESS;
        }

        $this->log('Processing Sneakpeek', icon: '👀');

        foreach ($files as $file) {
            $nataeroDispatcher(
                $file,
                FileOperationName::SNEAKPEEK,
                NataeroFunctionType::SNEAKPEEK,
                NataeroSneakpeekDispatchJob::class
            );
            $this->dispatchCount++;
        }

        $this->endLog();

        return self::SUCCESS;
    }
}
