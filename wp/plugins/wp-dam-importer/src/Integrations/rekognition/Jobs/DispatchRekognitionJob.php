<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Jobs;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use Aws\Exception\AwsException;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Rekognition;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Middleware\ThrottleRekognitionJobs;

class DispatchRekognitionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable,
        InteractsWithQueue,
        Loggable,
        Queueable,
        SerializesModels;

    public $timeout = 3600;

    public $tries = 3;

    private Rekognition $rekognition;

    public function __construct(public string $aiObject, public File $file)
    {
        $this->onQueue(QueueRouter::route('api'));
    }

    public function middleware(): array
    {
        return [new ThrottleRekognitionJobs];
    }

    public function handle(Rekognition $rekognition): void
    {
        $this->log(
            text: "Processing {$this->file->type} Rekognition {$this->aiObject} for file ID: {$this->file->id}",
            context: [
                'file'      => $this->file->id,
                'file_type' => $this->file->type,
                'ai_object' => $this->aiObject,
            ]
        );

        try {
            // NOTE: these 2 conditions must be here, it will not add a task without job_id

            if (empty($this->file->view_url)) {
                $this->log('No file view URL found to process', 'warn');

                return;
            }

            if ($this->file->shouldSendToMediaConvert() && Rekognition::maxOpenJobsExceeded()) {
                $this->log('Max open jobs exceeded', 'warn');

                return;
            }

            try {
                $rekognition->process($this->file, $this->aiObject);
            } catch (AwsException $e) {
                if (is_concurrent_limit_exceeded($e)) {
                    $this->log(
                        text: 'AWS concurrent job limit exceeded - releasing job for retry',
                        level: 'warning',
                        context: [
                            'file'      => $this->file->id,
                            'ai_object' => $this->aiObject,
                            'error'     => $e->getMessage(),
                        ]
                    );

                    $this->release(60);

                    return;
                }

                throw $e;
            }
        } catch (Exception $e) {
            $this->log(
                text: "Failed to process Rekognition for file {$this->file->id}",
                level: 'error',
                context: [
                    'error' => $e->getMessage(),
                    'file'  => $this->file->id,
                    'ai'    => $this->aiObject,
                    'trace' => $e->getTraceAsString(),
                ]
            );
        }
    }

    public function uniqueId(): string
    {
        return class_basename($this) . '_' . $this->file->id . '_' . $this->aiObject;
    }
}
