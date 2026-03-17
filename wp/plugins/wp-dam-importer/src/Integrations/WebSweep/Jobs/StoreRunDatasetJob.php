<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Jobs;

use RuntimeException;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Models\WebSweepRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Service\WebSweepService;

class StoreRunDatasetJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue,
        Queueable;

    public int $uniqueFor = 300;

    public int $timeout = 90;

    public int $tries = 2;

    public function __construct(
        public string $apifyRunId,
        public ?Collection $dataset = null,
    ) {
        $this->onQueue(QueueRouter::route('sync'));
    }

    public function handle(): void
    {
        /** @var WebSweepRun|null $run */
        $run = WebSweepRun::find($this->apifyRunId);

        // Fallback: sometimes the job is dispatched with Apify's run id, not our PK
        if (! $run) {
            $run = WebSweepRun::query()
                ->where('run_id', $this->apifyRunId)
                ->latest()
                ->first();
        }

        // If we still don't have a run, retry with backoff (bounded by $tries)
        if (! $run) {
            if ($this->attempts() >= $this->tries) {
                $this->fail(new RuntimeException("WebSweepRun not found after {$this->attempts()} attempts."));

                return;
            }

            $this->release(30);

            return;
        }

        WebSweepService::storeDatasetItems($run->fresh(), $this->dataset);
    }

    public function uniqueId(): string
    {
        return class_basename($this) . "_{$this->apifyRunId}";
    }
}
