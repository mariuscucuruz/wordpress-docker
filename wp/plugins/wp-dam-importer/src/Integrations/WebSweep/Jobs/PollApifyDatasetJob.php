<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Jobs;

use RuntimeException;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\WebSweep;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Contracts\Queue\ShouldQueue;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Models\WebSweepRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Enums\WebSweepRunStatus;

class PollApifyDatasetJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Loggable, Queueable;

    public int $uniqueFor = 90;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(
        public string $apifyRunId,
        public bool $recursive,
    ) {
        $this->onQueue(QueueRouter::route('api'));
    }

    public function handle(): void
    {
        /** @var WebSweepRun $run */
        $run = WebSweepRun::findOrFail($this->apifyRunId);

        $websweep = app(WebSweep::class);

        $runData = $websweep->getRun($run->run_id);

        if (! $runData) {
            throw new RuntimeException("Failed to fetch run data for WebSweepRun ID {$run->id} (Apify Run ID: {$run->run_id}).");
        }

        $run->updateWithRunData($runData);
        $run->refresh();

        if (empty($run->dataset_id)) {
            $customName = "{$run->service_id}-dataset";
            $datasetId = $websweep->findDatasetIdByName($customName);

            if (filled($datasetId)) {
                $run->update(['dataset_id' => $datasetId]);
            } else {
                $this->log("Custom dataset ID not found: {$customName}", 'warn', null, [
                    'run_id'     => $run->run_id,
                    'service_id' => $run->service_id,
                ]);
            }
        }

        if (empty($run->request_queue_id)) {
            $customName = "{$run->service_id}-request-queue";
            $requestQueueId = $websweep->findRequestQueueIdByName($customName);

            if (filled($requestQueueId)) {
                $run->update(['request_queue_id' => $requestQueueId]);
            } else {
                $this->log("Custom request queue not found: {$customName}", 'warn', null, [
                    'run_id'     => $run->run_id,
                    'service_id' => $run->service_id,
                ]);
            }
        }

        if (filled($run->dataset_id)) {
            dispatch(new StoreRunDatasetJob($run->id));
        }

        if ($this->recursive) {
            if (WebSweepRunStatus::stoppedCases()->contains($run->status)) {
                return;
            }

            if (($run->total_checks ?? 0) >= 12) {
                return;
            }

            dispatch(new self($this->apifyRunId, true))
                ->afterResponse()
                ->delay(now()->addMinutes(5));
        }
    }

    public function uniqueId(): string
    {
        return class_basename($this) . "_{$this->apifyRunId}";
    }
}
