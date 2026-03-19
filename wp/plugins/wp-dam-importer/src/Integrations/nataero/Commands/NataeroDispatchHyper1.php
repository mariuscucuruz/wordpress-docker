<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Enums\AssetType;
use MariusCucuruz\DAMImporter\Models\AdminSetting;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use Illuminate\Support\Collection;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Actions\NataeroDispatcher;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Jobs\NataeroHyper1DispatchJob;

class NataeroDispatchHyper1 extends Command
{
    use Loggable;

    public const string SIGNATURE = 'nataero:dispatch:hyper1';

    public int $dispatchCount = 0;

    protected $signature = self::SIGNATURE
        . ' {--fileID=}'
        . ' {--teamID=}'
        . ' {--serviceID=}'
        . ' {--forceOverwrite}'
        . ' {--audio}'
        . ' {--force}'
        . ' {--limit=}';

    protected $description = 'Enqueue asset hyper1 through the Nataero API.';

    private bool $forceOverwrite;

    public function handle(NataeroDispatcher $nataeroDispatcher): int
    {
        if (! AdminSetting::isNataeroAutoDispatchEnabled() && ! $this->option('force')) {
            $this->log('Nataero auto dispatch is disabled. Enable it in Nova or use --force to override the setting');

            return self::SUCCESS;
        }

        $this->startLog();

        if ($this->option('fileID')) {
            $file = File::findOrFail($this->option('fileID'));
            $nataeroDispatcher(
                $file,
                FileOperationName::HYPER1,
                NataeroFunctionType::HYPER1,
                NataeroHyper1DispatchJob::class
            );
            $this->endLog();

            return self::SUCCESS;
        }

        $this->forceOverwrite = (bool) $this->option('forceOverwrite');

        /** @var Collection<File> $files */
        $files = File::with(['nataeroTasks', 'hyper1ImageEmbedding', 'hyper1VideoEmbedding'])
            ->whereNotNull('download_url')
            ->whereNotNull('view_url')
            ->whereIn('type', [AssetType::Image->value, AssetType::Video->value])
            ->whereNot('extension', 'gif')
            ->when(! $this->forceOverwrite, function ($query) {
                $query->whereDoesntHave('nataeroTasks', fn ($q) => $q
                    ->where('function_type', strtoupper(NataeroFunctionType::HYPER1->value))
                )
                    ->whereDoesntHave('hyper1ImageEmbedding')
                    ->whereDoesntHave('hyper1VideoEmbedding');
            })
            ->limit($nataeroDispatcher->parseNumber($this->option('limit')))
            ->get();

        if ($files->isEmpty()) {
            $this->log('No unprocessed files found for hyper1');

            return self::SUCCESS;
        }

        $this->log('Processing hyper1', icon: '👀');

        foreach ($files as $file) {
            $nataeroDispatcher(
                $file,
                FileOperationName::HYPER1,
                NataeroFunctionType::HYPER1,
                NataeroHyper1DispatchJob::class
            );
        }

        $this->endLog();

        return self::SUCCESS;
    }
}
