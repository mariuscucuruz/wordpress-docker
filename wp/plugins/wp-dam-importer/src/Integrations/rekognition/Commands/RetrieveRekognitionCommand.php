<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Commands;

use MariusCucuruz\DAMImporter\Support\HorizonJobs;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Models\RekognitionTask;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionJobStatus;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Jobs\RetrieveRekognitionJob;

class RetrieveRekognitionCommand extends Command
{
    use Loggable;

    public const string SIGNATURE = 'media:retrieve-rekognition';

    protected $signature = self::SIGNATURE;

    protected $description = 'Retrieves the Rekognition results.';

    public function handle(): int
    {
        $this->startLog();

        RekognitionTask::query()
            ->oldest()
            ->whereNotNull('job_id')
            ->where('analyzed', false)
            ->where(function (Builder $query) {
                $query
                    ->whereNull('job_status')
                    ->orWhereIn('job_status', [RekognitionJobStatus::IN_PROGRESS, RekognitionJobStatus::PENDING]);
            })
            ->with('file')
            ->whereHas('file', fn (Builder $query) => $query->whereNotNull('view_url'))
            ->limit(HorizonJobs::queueLimit('api'))
            ->cursor()
            ->each(function ($rekognition) {
                if ($rekognition->created_at->diffInDays(now()) > 2) {
                    $rekognition->update(['job_status' => RekognitionJobStatus::TIMED_OUT]);

                    return;
                }
                $file = $rekognition->file;
                $this->log("Getting Rekognition data for ({$file->id})");

                dispatch(new RetrieveRekognitionJob($file));
            });

        $this->endLog();

        return self::SUCCESS;
    }
}
