<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Commands;

use MariusCucuruz\DAMImporter\Models\AdminSetting;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Actions\RekognitionByService;

class EnqueueRekognitionCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:enqueue-rekognition';

    protected $signature = self::SIGNATURE
        . ' {--o|texts : Process texts}'
        . ' {--t|transcribes : Process transcribes}'
        . ' {--c|celebrities : Process celebrities}'
        . ' {--s|segments : Process segments}'
        . ' {--A|all : Process everything}'
        . ' {--manual : Process only manual actions}'
        . ' {--fileId= : ID of the specific file}'
        . ' {--teamId= : ID of the specific team}';

    protected $description = 'Sends to the Rekognition to process.';

    public function handle(RekognitionByService $rekognitionByService): ?int
    {
        $this->startLog();

        if (! $this->hasValidOptions()) {
            $this->endLog();

            return self::INVALID;
        }

        $aiObjects = config('rekognition.ai_objects');

        if ($this->option('all')) {
            foreach ($aiObjects as $aiObject) {
                if (! AdminSetting::isRekognitionObjectEnabled($aiObject)) {
                    $this->log("Rekognition object is disabled for {$aiObject}", 'warn');

                    continue;
                }
                $this->log("Dispatch Rekognition job for: {$aiObject}");
                $rekognitionByService->handle(
                    $aiObject,
                    $this->option('teamId'),
                    $this->option('fileId'),
                    $this->option('manual')
                );
            }

            $this->endLog();

            return self::SUCCESS;
        }

        $passedOptions = [];

        foreach ($aiObjects as $option) {
            if ($this->option($option)) {
                $passedOptions[] = $option;
            }
        }

        if (empty($passedOptions)) {
            $this->log('No valid options', 'error');

            return self::INVALID;
        }

        foreach ($passedOptions as $option) {
            $this->log("Processing Rekognition for: {$option}");

            $rekognitionByService->handle(
                $option,
                $this->option('teamId'),
                $this->option('fileId'),
                $this->option('manual')
            );
        }

        $this->endLog();

        return self::SUCCESS;
    }

    private function hasValidOptions(): bool
    {
        $options = collect([...config('rekognition.ai_objects'), 'all']);
        $isValid = $options->contains(fn ($option) => $this->option($option));

        if (! $isValid) {
            $this->log('At least one option must be provided.', 'error');

            return false;
        }

        return true;
    }
}
