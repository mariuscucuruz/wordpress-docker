<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Commands;

use MariusCucuruz\DAMImporter\Support\HorizonJobs;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Models\FileOperationState;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Jobs\RetrieveAcrCloudJob;

class RetrieveAcrCloudCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:acrcloud-retrieve';

    const int MAX_TRIES = 3;

    protected $signature = self::SIGNATURE;

    protected $description = 'Command to fetch music detection results.';

    public function handle(): int
    {
        $this->startLog();

        $this->retrieveProcessingTasks();

        $this->endLog();

        return self::SUCCESS;
    }

    private function retrieveProcessingTasks(): void
    {
        FileOperationState::query()
            ->with('file')
            ->where('operation_name', FileOperationName::ACRCLOUD)
            ->where('status', FileOperationStatus::PROCESSING)
            ->limit(HorizonJobs::queueLimit('long-running-task'))
            ->oldest()
            ->cursor()
            ->each(function (FileOperationState $fileOperationStateTask) {
                $this->dispatchRetrieveAcrJob($fileOperationStateTask);
            });
    }

    private function dispatchRetrieveAcrJob(FileOperationState $fileOperationStateTask): void
    {
        dispatch(new RetrieveAcrCloudJob($fileOperationStateTask));
        $this->log(config('acrcloud.key', 'ACRCLOUD') . " checking result for file id ({$fileOperationStateTask->file->id})", icon: '👀');
    }
}
