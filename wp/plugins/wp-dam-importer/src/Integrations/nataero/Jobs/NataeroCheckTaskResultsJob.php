<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Jobs;

use Throwable;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Nataero;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\NataeroTask;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;

class NataeroCheckTaskResultsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Loggable, Queueable;

    public $timeout = 3600;

    public $tries = 1;

    public function __construct(
        public NataeroTask $task,
        public NataeroFunctionType $nataeroFunctionType
    ) {
        $this->onQueue(QueueRouter::route('api'));
    }

    public function handle(Nataero $nataero): void
    {
        $nataero->getNataeroFileResults($this->task, $this->nataeroFunctionType);
    }

    public function failed(Throwable $exception): void
    {
        $this->log(
            text: 'Nataero Task ID Results dispatch job failed',
            level: 'error',
            icon: '❌',
            context: [
                'file_id'   => $this->task->file->id,
                'exception' => $exception,
            ]
        );

        $this->task->file->markFailure(
            FileOperationName::tryFrom(strtolower($this->nataeroFunctionType->value)),
            'Nataero Task ID Results dispatch job failed: ' . $exception->getMessage()
        );
    }

    public function uniqueId(): string
    {
        return class_basename($this) . '_' . $this->task->id;
    }
}
