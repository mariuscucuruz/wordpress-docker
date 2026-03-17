<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Commands;

use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\NataeroTask;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroTaskStatus;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;

class NataeroBackfillHyper1FOSCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'nataero:backfill:hyper1-fos';

    protected $signature = self::SIGNATURE . '
        {--dry-run : Show what would be done without making changes}
        {--limit= : Limit the number of tasks to process}
        {--service= : Filter by service_id}';

    protected $description = 'Backfill file_operation_states for SUCCEEDED Hyper1 tasks that have embeddings but no FOS record.';

    public function handle(): int
    {
        $this->startLog();

        $isDryRun = $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $serviceId = $this->option('service');

        if ($isDryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $query = NataeroTask::query()
            ->where('function_type', NataeroFunctionType::HYPER1->value)
            ->where('status', NataeroTaskStatus::SUCCEEDED->value)
            ->whereHas('file', function ($q) {
                $q->where('type', 'image');
            })
            ->whereHas('hyper1ImageEmbedding')
            ->whereDoesntHave('file.operationStates', function ($q) {
                $q->where('operation_name', FileOperationName::HYPER1->value)
                    ->where('media_type', 'image');
            })
            ->with(['file', 'hyper1ImageEmbedding']);

        if ($serviceId) {
            $query->whereHas('file', function ($q) use ($serviceId) {
                $q->where('service_id', $serviceId);
            });
        }

        $totalCount = (clone $query)->count();
        $this->info("Found {$totalCount} SUCCEEDED Hyper1 tasks missing FOS records");

        if ($totalCount === 0) {
            $this->info('Nothing to backfill.');
            $this->endLog();

            return self::SUCCESS;
        }

        if ($limit) {
            $query->limit($limit);
            $this->info("Processing limited to {$limit} tasks");
        }

        $processed = 0;
        $failed = 0;

        $progressBar = $this->output->createProgressBar($limit ?? $totalCount);
        $progressBar->start();

        $query->chunkById(500, function ($tasks) use ($isDryRun, &$processed, &$failed, $progressBar) {
            foreach ($tasks as $task) {
                try {
                    $file = $task->file;

                    if (! $file) {
                        $this->log("Skipping task {$task->id} - file not found");
                        $failed++;
                        $progressBar->advance();

                        continue;
                    }

                    if (! $isDryRun) {
                        $file->markSuccess(
                            FileOperationName::HYPER1,
                            'Backfilled FOS for legacy Hyper1 task',
                            [
                                'remote_task_id' => $task->remote_nataero_task_id,
                                'backfilled_at'  => now()->toIso8601String(),
                                'media_type'     => 'image',
                            ]
                        );
                    }

                    $processed++;
                    $this->log("Processed task {$task->id} for file {$file->id}");
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

        $this->endLog();

        return self::SUCCESS;
    }
}
