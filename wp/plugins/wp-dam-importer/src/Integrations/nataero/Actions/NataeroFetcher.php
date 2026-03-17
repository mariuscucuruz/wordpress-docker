<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Actions;

use Illuminate\Database\Eloquent\Builder;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\NataeroTask;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroTaskStatus;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Jobs\NataeroCheckTaskResultsJob;

class NataeroFetcher
{
    public static function query($function, bool $force = false, int $createdAtMin = 10, $limit = 50): Builder
    {
        return NataeroTask::with('file')
            ->where('function_type', $function)
            ->when(! $force, function ($query) use ($createdAtMin) {
                $query->whereIn('status', [
                    NataeroTaskStatus::PROCESSING,
                ])->where('created_at', '<=', now()->subMinutes($createdAtMin));
            })
            ->whereNotNull('remote_nataero_task_id')
            ->whereHas('file')
            ->limit($limit);
    }

    public static function dispatcher(NataeroTask $task, NataeroFunctionType $nataeroFunctionType, $sync = false): void
    {
        $task->update([
            'status' => NataeroTaskStatus::CHECKING_RESULTS,
        ]);

        if ($sync) {
            dispatch_sync(new NataeroCheckTaskResultsJob($task, $nataeroFunctionType));
        } else {
            dispatch(new NataeroCheckTaskResultsJob($task, $nataeroFunctionType));
        }
    }
}
