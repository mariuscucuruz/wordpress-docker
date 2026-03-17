<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\Service;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionTypes;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionJobStatus;

class MediaRekognitionForgetCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:forget:rekognition';

    protected $signature = self::SIGNATURE
        . ' {--o|texts : Target texts}'
        . ' {--t|transcribes : Target transcribes}'
        . ' {--c|celebrities : Target celebrities}'
        . ' {--s|segments : Target segments}'
        . ' {--a|all : Target all AI objects}'
        . ' {--force : Sets tasks to null and analyzed to false for tasks where job_id is null}'
        . ' {--delete : Delete tasks that are failed }'
        . ' {--serviceId= : ID of the specific service}'
        . ' {--fileId= : ID of the specific file}';

    protected $description = 'Clears Rekognition data for specified files and resets operation state.';

    public function handle(): ?int
    {
        $this->startLog();

        $serviceId = $this->option('serviceId');
        $fileId = $this->option('fileId');
        $fileQuery = File::withoutGlobalScopes()->with('rekognitionTasks');

        if ($this->option('force')) {
            $fileQuery->whereHas('rekognitionTasks', fn ($q) => $q
                ->where('job_status', RekognitionJobStatus::FAILED)
                ->whereNull('job_id'));
        } elseif ($this->option('delete')) {
            $fileQuery->whereHas('rekognitionTasks', fn ($q) => $q
                ->where('job_status', RekognitionJobStatus::FAILED)
                ->orWhereNull('job_status'));
        } else {
            $fileQuery->whereHas('rekognitionTasks', fn ($q) => $q
                ->where('job_status', RekognitionJobStatus::FAILED));
        }

        if ($fileId) {
            $fileQuery->where('id', $fileId)
                ->cursor()
                ->each(fn ($file) => $this->processFile($file));
        }

        if ($serviceId) {
            Service::findOrFail($serviceId)
                ->files()
                ->chunkById(100, fn ($files) => $files->each(fn (File $file) => $this->processFile($file)));
        } else {
            $fileQuery->chunkById(100, fn ($files) => $files->each(fn (File $file) => $this->processFile($file)));
        }

        $this->endLog();

        return self::SUCCESS;
    }

    private function processFile(File $file): void
    {
        rescue(function () use ($file) {
            if ($this->option('force') || $this->option('delete')) {
                $this->clearFailedAi($file);
            } elseif ($this->option('all')) {
                $this->clearAllAi($file);
            } else {
                $this->clearSpecificAi($file);
            }

            $this->concludedLog("File {$file->id} Rekognition data cleared", '♻️');
        }, function ($e) use ($file) {
            $this->log("Error clearing file ID: {$file->id} - {$e?->getMessage()}", 'error');
        });
    }

    private function clearFailedAi(File $file): void
    {
        $this->log("Clearing failed AI data for file ID: {$file->id}", icon: '⏳');

        $failedTasks = $file->rekognitionTasks()->where('job_status', RekognitionJobStatus::FAILED)->get();

        if ($failedTasks->isEmpty()) {
            $this->log("No failed AI data found for file ID: {$file->id}", 'warn');

            return;
        }

        $typesToClear = $failedTasks->pluck('job_type')->toArray();

        $this->clearAiData($file, $typesToClear, true);
    }

    private function clearAllAi(File $file): void
    {
        $this->log("Clearing all AI data for file ID: {$file->id}", icon: '⏳');
        $this->clearAiData($file, config('rekognition.ai_objects'), true);
    }

    private function clearSpecificAi(File $file): void
    {
        $this->log("Clearing specific AI data for file ID: {$file->id}", icon: '⏳');
        $typesToClear = array_filter(
            config('rekognition.ai_objects'),
            fn ($rekAiObj) => $this->option($rekAiObj)
        );

        $this->clearAiData($file, $typesToClear, false);
    }

    private function clearAiData(File $file, array $types, bool $isAll): void
    {
        if (empty($types)) {
            $this->log("No Rekognition types selected for file ID: {$file->id}", 'warn');

            return;
        }

        if ($file->rekognitionTasks()->doesntExist()) {
            $this->log("No rekognition tasks data found for file ID: {$file->id}", 'warn');

            return;
        }

        foreach ($types as $type) {
            if ($type instanceof RekognitionTypes) {
                $type = $type->value;
            }

            if ($isAll || $this->option($type)) {
                $this->log("Clearing AI {$type} data for file ID: {$file->id}", icon: '🧹');

                $rekognitionTask = $file->rekognitionTasks()->where('job_type', $type)->first();

                if (! $rekognitionTask) {
                    $this->log("No AI {$type} data found for file ID: {$file->id}", 'warn');

                    continue;
                }

                if (method_exists($rekognitionTask, $type)) {
                    rescue(fn () => $rekognitionTask->{$type}()?->delete());
                }

                $method = str($type)->singular()->toString() . 'Detections';

                if (method_exists($rekognitionTask, $method)) {
                    rescue(fn () => $rekognitionTask->{$method}()?->delete());
                }

                $this->concludedLog(ucfirst($type) . ' Cleared', '✅', '-', 6);

                if ($this->option('force')) {
                    $rekognitionTask->update([
                        'job_status' => null,
                        'analyzed'   => false,
                        'attempts'   => 0,
                    ]);
                }

                if ($file->type === FunctionsType::Image->value || $this->option('delete')) {
                    $rekognitionTask->delete();
                }
            }
        }

        $this->log("Rekognition reset complete for file {$file->id}", 'info', '♻️');
    }
}
