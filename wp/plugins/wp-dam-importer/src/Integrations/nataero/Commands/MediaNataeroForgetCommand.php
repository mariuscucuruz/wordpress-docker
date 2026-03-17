<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Commands;

use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use Illuminate\Support\Facades\DB;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\NataeroTask;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroTaskStatus;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;

class MediaNataeroForgetCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:forget:nataero';

    protected $signature = self::SIGNATURE
        . ' {--serviceId= : ID of the specific service}'
        . ' {--fileId= : ID of the specific file}'
        . ' {--taskId= : ID of the specific Nataero Task}'
        . ' {--status= : Status of the Nataero Tasks}'
        . ' {--function= : Function type of the Nataero Tasks}';

    protected $description = 'Reset Nataero statuses and operation states for specified files or tasks.';

    public function handle(): int
    {
        $this->startLog();

        $taskId = $this->option('taskId');
        $serviceId = $this->option('serviceId');
        $fileId = $this->option('fileId');
        $status = $this->option('status');
        $function = $this->option('function');

        $query = NataeroTask::query();

        if (empty($taskId) && empty($serviceId) && empty($fileId) && empty($status)) {
            $this->log('Please provide at least one of: serviceId, fileId, or status.', 'error');

            return self::INVALID;
        }

        if (empty($function)) {
            // Do not include CONVERT tasks by default as this could reset thumbnail and view_urls.
            // You have to specify --function=CONVERT if you want to reset those tasks.
            $query->where('function_type', '!=', NataeroFunctionType::CONVERT->value);
        } else {
            $validFunction = NataeroFunctionType::tryFrom($function);

            if (empty($validFunction)) {
                $this->log(
                    'Invalid function type. Please provide a valid function type: '
                    . implode(',', array_map(fn ($case) => $case->value, NataeroFunctionType::cases())),
                    'error'
                );

                return self::INVALID;
            }

            $query->where('function_type', $function);
        }

        if (filled($taskId)) {
            $query->where('id', $taskId);
        }

        if (filled($fileId)) {
            $query->whereHas('file', fn ($q) => $q->where('id', $fileId));
        }

        if (filled($serviceId)) {
            $query->whereHas('file', fn ($q) => $q->where('service_id', $serviceId));
        }

        if (filled($status)) {
            $validStatus = NataeroTaskStatus::tryFrom($status);

            if (empty($validStatus)) {
                $this->log(
                    'Invalid status. Please provide a valid status: '
                    . implode(',', array_map(fn ($case) => $case->value, NataeroTaskStatus::cases())),
                    'error'
                );

                return self::INVALID;
            }

            $query->where('status', $status);
        }

        $count = $query->count();

        if ($count === 0) {
            $this->log('No tasks found matching the provided criteria.', 'warning');

            return self::SUCCESS;
        }

        $this->log("Found {$count} matching tasks.");

        if ($function === NataeroFunctionType::CONVERT->value
            && ! $this->confirm('You are about to reset CONVERT tasks. This will clear thumbnails and view URLs. Continue?')) {
            $this->log('Operation cancelled by user.');

            return self::INVALID;
        }

        $query->chunkById(500, function ($tasks) {
            $tasks->each(fn (NataeroTask $task) => $this->resetStatus($task));
        });

        $this->endLog();

        return self::SUCCESS;
    }

    private function resetStatus(NataeroTask $task): void
    {
        $task->load('file');
        $file = $task->file;

        if ($file === null) {
            $this->log("Task {$task->id} has no associated file.", 'warning');

            return;
        }

        DB::transaction(function () use ($task, $file) {
            $functionType = $task->function_type;

            $task->delete();

            match ($functionType) {
                NataeroFunctionType::CONVERT->value => $this->resetConvertTask($file),
                NataeroFunctionType::HYPER1->value  => $this->resetHyper1Task($file),
                default                             => null,
            };

            $this->log("Reset completed for Nataero {$functionType} task (File ID: {$file->id})", 'info', '♻️');
        });
    }

    private function resetConvertTask($file): void
    {
        $file->operationStates()
            ->where('operation_name', FileOperationName::CONVERT)
            ->delete();

        $file->update([
            'thumbnail' => null,
        ]);
    }

    private function resetHyper1Task($file): void
    {
        $file->operationStates()
            ->where('operation_name', FileOperationName::HYPER1)
            ->delete();

        $file->hyper1ImageEmbedding()->delete();
        $file->hyper1VideoEmbedding()->delete();
        $file->hyper1AverageVideoEmbedding()->delete();
    }
}
