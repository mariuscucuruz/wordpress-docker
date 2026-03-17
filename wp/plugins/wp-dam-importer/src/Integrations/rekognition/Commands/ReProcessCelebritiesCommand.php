<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\CelebrityDetection;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Jobs\ProcessCelebritiesJob;

class ReProcessCelebritiesCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:reprocess-celebrities';

    protected $signature = self::SIGNATURE;

    protected $description = 'Reprocess celebrities for files that have not been processed.';

    public function handle(): int
    {
        $this->startLog();

        $this->log('Scanning all failed/incomplete celebrity detections...');

        $currentFileId = null;
        $buffer = [];

        CelebrityDetection::query()
            ->where(fn ($query) => $query
                ->whereNull('instances')
                ->orWhereRaw("(instances->>'imageUrl') IS NULL")
                ->orWhereRaw("(instances->>'imageUrl') = ''")
            )
            ->orderBy('file_id')
            ->cursor()
            ->each(function (CelebrityDetection $detection) use (&$currentFileId, &$buffer) {
                if ($currentFileId !== $detection->file_id) {
                    $this->dispatchIfNeeded($currentFileId, $buffer);
                    $currentFileId = $detection->file_id;
                    $buffer = [];
                }

                $buffer[] = [
                    'id'          => $detection->id,
                    'name'        => $detection->name,
                    'confidence'  => $detection->confidence,
                    'time'        => $detection->time,
                    'urls'        => data_get($detection, 'instances.urls', []),
                    'knownGender' => data_get($detection, 'instances.knownGender', ''),
                ];
            });

        $this->dispatchIfNeeded($currentFileId, $buffer);

        $this->concludedLog('Done');
        $this->endLog();

        return self::SUCCESS;
    }

    protected function dispatchIfNeeded(?string $fileId, array $items): void
    {
        if (! $fileId || empty($items)) {
            return;
        }

        $file = File::find($fileId);

        if (! $file) {
            $this->log("File not found for file_id: {$fileId}", 'error');

            return;
        }

        dispatch(new ProcessCelebritiesJob($file, $items));
        $this->log("Dispatched repair job for file_id: {$fileId}");
    }
}
