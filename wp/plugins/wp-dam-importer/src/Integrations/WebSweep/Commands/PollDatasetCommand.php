<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Commands;

use MariusCucuruz\DAMImporter\Models\Service;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Models\WebSweepRun;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotQuery;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Jobs\PollApifyDatasetJob;

class PollDatasetCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'websweep:poll';

    protected $signature = self::SIGNATURE
        . ' { --runId= : The ID / UUID of the run }'
        . ' { --serviceId= : The ID of the service }';

    protected $description = 'Fetch a WebSweep crawl.';

    public function handle(): int
    {
        $this->startLog();

        $runId = $this->option('runId');
        $serviceId = $this->option('serviceId');

        if (empty($serviceId) && empty($runId)) {
            $this->log('Required runId OR serviceId not provided.', 'error');
            $this->endLog();

            return self::INVALID;
        }

        if (filled($serviceId) && empty($runId)) {
            $service = Service::query()->find($serviceId);
            throw_unless($service, CouldNotQuery::class, 'Invalid service ID provided.');

            /** @var WebSweepRun $run */
            $run = WebSweepRun::query()
                ->where('service_id', $service->id)
                ->whereNotNull('start_url')
                ->latest()
                ->first();
        }

        if (filled($runId)) {
            if (str($runId)->isUuid()) {
                $run = WebSweepRun::find($runId);
            } else {
                $run = WebSweepRun::where('run_id', $runId)->first();
            }
        }

        if (! isset($run) || empty($run)) {
            $this->log('Could not find RUN.', 'error');
            $this->endLog();

            return self::FAILURE;
        }

        dispatch(new PollApifyDatasetJob($run->id, true));

        $this->log('Dispatched poll job.');
        $this->endLog();

        return self::SUCCESS;
    }
}
