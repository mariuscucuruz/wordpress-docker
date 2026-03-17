<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Jobs;

use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Mediaconvert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use MariusCucuruz\DAMImporter\Integrations\Mediaconvert\MediaconvertFfmpeg;

class DispatchMediaConvertJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Loggable, Queueable, SerializesModels;

    public $timeout = 3600;

    public $uniqueFor = 3600;

    public $tries = 1;

    public function __construct(public File $file, public string|int|null $userId = null)
    {
        $queueName = $this->runOnAws() ? 'long-running-task' : 'ffmpeg';
        $this->onQueue(QueueRouter::route($queueName));
    }

    public function handle(Mediaconvert $mediaconvert, MediaconvertFfmpeg $mediaconvertFfmpeg): void
    {
        if (! $this->runOnAws()) {
            $mediaconvertFfmpeg->process($this->file);

            return;
        }

        $mediaconvert->process($this->file);
    }

    public function uniqueId(): string
    {
        return class_basename($this) . '_' . $this->file->id;
    }

    private function runOnAws(): bool
    {
        return config('mediaconvert.binary') === 'aws';
    }

    public function failed(Throwable $exception)
    {
        $this->log('Dispatch media convert job failed', 'error', null, [
            'file_id'   => $this->file->id,
            'exception' => $exception,
        ]);
    }
}
