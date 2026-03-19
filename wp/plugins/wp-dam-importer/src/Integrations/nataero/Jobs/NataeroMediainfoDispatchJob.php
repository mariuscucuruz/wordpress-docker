<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Jobs;

use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Nataero;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use MariusCucuruz\DAMImporter\Integrations\Nataero\DTO\OperationParams;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;

class NataeroMediainfoDispatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Loggable, Queueable, SerializesModels;

    public function __construct(public File $file)
    {
        $this->onQueue(QueueRouter::route('api'));
    }

    public function handle(Nataero $nataero): void
    {
        if (! $nataero->processOperation($this->file, new OperationParams(nataeroFunctionType: NataeroFunctionType::MEDIAINFO))) {
            $this->log("Mediainfo failed for File ID {$this->file->id}", 'error');

            $this->fail(new \RuntimeException('Nataero mediainfo failed'));
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->log(
            text: 'Nataero mediainfo dispatch job failed',
            level: 'error',
            icon: '❌',
            context: [
                'file_id'   => $this->file->id,
                'exception' => $exception,
            ]
        );
    }

    public function uniqueId(): string
    {
        return class_basename($this) . '_' . $this->file->id;
    }
}
