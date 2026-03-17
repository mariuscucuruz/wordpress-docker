<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Commands;

use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Models\FileOperationState;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Enums\AcrCloudFileResponseState;

class ReprocessAcrCloudNoResultsCommand extends Command
{
    use Loggable;

    protected $signature = 'media:acrcloud-fix-noresults';

    protected $description = 'Mark failed ACRCloud states with NO_RESULTS as success.';

    public function handle(): int
    {
        $this->startLog();

        $count = FileOperationState::query()
            ->where('operation_name', FileOperationName::ACRCLOUD)
            ->where('status', FileOperationStatus::FAILED)
            ->whereJsonContains('data->response_state', AcrCloudFileResponseState::NO_RESULTS->value)
            ->update([
                'status'  => FileOperationStatus::SUCCESS,
                'message' => 'ACRCloud completed with no results (backfilled)',
            ]);

        $this->log("Updated {$count} records");

        $this->endLog();

        return self::SUCCESS;
    }
}
