<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Enums\AssetType;
use MariusCucuruz\DAMImporter\Models\AdminSetting;
use MariusCucuruz\DAMImporter\Support\HorizonJobs;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Jobs\ProcessAcrCloudJob;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Models\AcrCloudMusicTrack;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\ServiceFunctionsEnum;

class DispatchAcrCloudCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:acrcloud-dispatch';

    protected $signature = self::SIGNATURE
        . ' {--force : Send an AI request even if ACRcloud has been disabled}'
        . ' {--ignoreStatus : Ignore existing ACRcloud operation states}'
        . ' {--serviceId= : ID of a specific service}'
        . ' {--fileId= : ID of a specific file}';

    protected $description = 'Enqueue ACRcloud Music detection jobs.';

    private string $taskName;

    private bool $ignoreStatus;

    public function handle(): int
    {
        $this->taskName = config('acrcloud.key', 'ACRCLOUD');
        $forced = (bool) $this->option('force');
        $serviceId = $this->option('serviceId');
        $fileId = $this->option('fileId');
        $this->ignoreStatus = (bool) $this->option('ignoreStatus');

        $this->startLog();

        if (! AdminSetting::isACRCloudEnabled() && ! $forced) {
            $this->log('ACRCloud is disabled');

            return self::SUCCESS;
        }

        if (filled($fileId)) {
            $file = File::find($fileId);

            if (! $file) {
                $this->log("File ID {$fileId} not found");
                $this->endLog();

                return self::INVALID;
            }

            $this->queryAndDispatch(file: $file);

            $this->log("File ID {$file->id} sent to {$this->taskName} for processing");
            $this->endLog();

            return self::SUCCESS;
        }

        if (filled($serviceId) && $service = Service::find($serviceId)) {
            $this->queryAndDispatch($service);

            $this->log("Service {$service->name} sent to {$this->taskName} for processing");
            $this->endLog();

            return self::SUCCESS;
        }

        $this->queryAndDispatch();

        $this->log("Sent to {$this->taskName} for processing");
        $this->endLog();

        return self::SUCCESS;
    }

    public function queryAndDispatch(?Service $service = null, ?File $file = null): void
    {
        $this->log('Finding files to send off to AcrCloud');

        $query = File::query()
            ->with('operationStates')
            ->whereIn('type', [AssetType::Audio, AssetType::Video])
            ->whereNotNull('view_url')
            ->when(! $this->ignoreStatus,
                fn (Builder $query) => $query->whereDoesntHave('operationStates',
                    fn (Builder $q) => $q->where('operation_name', FileOperationName::ACRCLOUD)
                )
            )
            ->whereDoesntHave('acrCloudMusicTracks')
            ->whereHas('service', fn (Builder $q) => $q
                ->serviceFunctionEnabled(ServiceFunctionsEnum::MUSIC, FunctionsType::Audio)
            );

        if (filled($service)) {
            $query->where('service_id', $service->id);
        }

        if (filled($file)) {
            $query->where('id', $file->id);
        }

        $query->limit(HorizonJobs::queueLimit('long-running-task'))
            ->cursor()
            ->each(function (File $file) {
                $this->processFileForAcrCloud($file);
            });
    }

    protected function processFileForAcrCloud(File $file): void
    {
        $operationMessage = 'Processing with ACRCloud';

        if ($similarFile = $this->foundSimilarFileAlreadyProcessed($file)) {
            $similarFile->acrCloudMusicTracks
                ?->each(fn (AcrCloudMusicTrack $similar) => $similar
                    ->replicate()
                    ->fill(['file_id' => $file->id])
                    ->save());

            $similarStatus = $similarFile->operationStates()
                ->where('operation_name', FileOperationName::ACRCLOUD)
                ->latest()
                ->first();

            if ($similarStatus?->status === FileOperationStatus::SUCCESS) {
                $file->markOperation(
                    FileOperationName::ACRCLOUD,
                    FileOperationStatus::SUCCESS,
                    "copied tracks from similar file {$similarFile->id}"
                );

                return;
            }

            $operationMessage = 'Similar file found (not successful); scheduling fresh detection';
        }

        $this->log("{$this->taskName}: {$operationMessage}", icon: '✅');

        $file->markInitialized(
            FileOperationName::ACRCLOUD,
            $operationMessage
        );

        dispatch(new ProcessAcrCloudJob($file));
        $this->log("{$this->taskName} processed for file ID: {$file->id}", icon: '✅');
    }

    protected function foundSimilarFileAlreadyProcessed(File $file): ?File
    {
        if (empty($file->md5)) {
            return null;
        }

        return $file->similarFiles()
            ->whereHas('acrCloudMusicTracks')
            ->whereHas('operationStates', fn (Builder $q) => $q
                ->where('operation_name', FileOperationName::ACRCLOUD)
                ->where('status', FileOperationStatus::SUCCESS)
            )
            ->first();
    }
}
