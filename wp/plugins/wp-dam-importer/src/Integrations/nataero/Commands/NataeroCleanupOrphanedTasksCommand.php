<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Commands;

use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\NataeroTask;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroTaskStatus;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;

class NataeroCleanupOrphanedTasksCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'nataero:cleanup:orphaned-tasks';

    protected $signature = self::SIGNATURE . '
        {--dry-run : Show what would be done without making changes}
        {--action=delete : Action to take: delete, mark-failed, or requeue}
        {--limit= : Limit the number of tasks to process}
        {--service= : Filter by service_id}';

    protected $description = 'Cleanup SUCCEEDED Hyper1 tasks that have no embeddings (orphaned tasks).';

    public function handle(): int
    {
        $this->startLog();

        $isDryRun = $this->option('dry-run');
        $action = $this->option('action');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $serviceId = $this->option('service');

        if (! in_array($action, ['delete', 'mark-failed', 'requeue'])) {
            $this->error("Invalid action: {$action}. Must be one of: delete, mark-failed, requeue");

            return self::FAILURE;
        }

        if ($isDryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $this->info("Action: {$action}");

        $query = NataeroTask::query()
            ->where('function_type', NataeroFunctionType::HYPER1->value)
            ->where('status', NataeroTaskStatus::SUCCEEDED->value)
            ->whereHas('file', function ($q) {
                $q->where('type', 'image');
            })
            ->whereDoesntHave('hyper1ImageEmbedding')
            ->with('file');

        if ($serviceId) {
            $query->whereHas('file', function ($q) use ($serviceId) {
                $q->where('service_id', $serviceId);
            });
        }

        $totalCount = (clone $query)->count();
        $this->info("Found {$totalCount} orphaned SUCCEEDED Hyper1 tasks (no embeddings)");

        if ($totalCount === 0) {
            $this->info('Nothing to cleanup.');
            $this->endLog();

            return self::SUCCESS;
        }

        if ($limit) {
            $query->limit($limit);
            $this->info("Processing limited to {$limit} tasks");
        }

        if (! $isDryRun && ! $this->confirm("Are you sure you want to {$action} these tasks?")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;

        $progressBar = $this->output->createProgressBar($limit ?? $totalCount);
        $progressBar->start();

        $query->chunkById(500, function ($tasks) use ($isDryRun, $action, &$processed, &$failed, $progressBar) {
            foreach ($tasks as $task) {
                try {
                    if (! $isDryRun) {
                        match ($action) {
                            'delete'      => $task->delete(),
                            'mark-failed' => $task->update([
                                'status'    => NataeroTaskStatus::FAILED->value,
                                'exception' => 'Marked as failed during cleanup - no embeddings found despite SUCCEEDED status',
                            ]),
                            'requeue' => $task->update([
                                'status'    => NataeroTaskStatus::INITIATED->value,
                                'exception' => null,
                            ]),
                        };
                    }

                    $processed++;
                    $this->log("Processed task {$task->id} with action: {$action}");
                } catch (\Throwable $e) {
                    $failed++;
                    $this->log("Failed to process task {$task->id}: {$e->getMessage()}", 'error');
                }

                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Completed: {$processed} processed, {$failed} failed");

        if ($isDryRun) {
            $this->warn('DRY RUN - No changes were made. Remove --dry-run to execute.');
        }

        if ($action === 'requeue') {
            $this->info('Tasks have been requeued. Run `nataero:dispatch:hyper1` to re-process them.');
        }

        $this->endLog();

        return self::SUCCESS;
    }
}
