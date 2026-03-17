<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Actions;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\Team;
use MariusCucuruz\DAMImporter\Support\HorizonJobs;
use MariusCucuruz\DAMImporter\Models\BusinessContract;
use MariusCucuruz\DAMImporter\Models\Scopes\FileScope;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Rekognition;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\ServiceFunctionsEnum;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums\RekognitionJobStatus;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Jobs\DispatchRekognitionJob;

class RekognitionByService
{
    use Loggable;

    private mixed $aiObject;

    private mixed $teamId;

    private mixed $fileId;

    private mixed $manual;

    public function handle($aiObject, $teamId = null, $fileId = null, $manual = null): void
    {
        $this->aiObject = $aiObject;
        $this->teamId = $teamId;
        $this->fileId = $fileId;
        $this->manual = $manual ?? false;

        if (BusinessContract::hasMinutesAllowanceLeft()) {
            $this->processFilesByType(FunctionsType::Video);
            $this->processFilesByType(FunctionsType::Audio);
        }

        $this->processFilesByType(FunctionsType::Image);
        $this->processFilesByType(FunctionsType::Image, true);
    }

    private function processFilesByType(FunctionsType $type, bool $childrenOnly = false): void
    {
        $fileQuery = File::query();

        $maxApiJobs = HorizonJobs::queueLimit('api', 100);

        if ($this->fileId) {
            $file = File::findOrFail($this->fileId);
            $fileQuery = $fileQuery->where('id', $file->id);
        }

        if ($this->teamId) {
            $team = Team::findOrFail($this->teamId);
            $fileQuery = $fileQuery->where('team_id', $team->id);
        }

        if (in_array($type->value, [FunctionsType::Audio->value, FunctionsType::Video->value], true)) {
            if (Rekognition::maxOpenJobsExceeded()) {
                $this->log('Max open jobs exceeded.', 'warn');

                return;
            }

            $maxApiJobs = Rekognition::availableOpenJobs();

            $fileQuery->where('duration', '>', 0);

            $fileQuery->when($type->value === FunctionsType::Video->value,
                fn ($q) => $q->where('duration', '<=', config('manager.max_duration_millisecond'))
            );
        }

        $actionsByType = ServiceFunctionsEnum::getDefaultActions($type, config('rekognition.name'));

        // we make sure here that we only process files that have the AI object set based on the type of the file
        if (! isset($actionsByType[$this->aiObject])) {
            return;
        }

        $fileQuery
            ->with('rekognitionTasks')
            ->when($childrenOnly,
                fn ($query) => $query
                    ->withoutGlobalScopes([FileScope::class])
                    ->whereNotNull('parent_id'),
            )
            ->whereNotNull('download_url')
            ->whereNotNull('view_url')
            ->where('type', $type)
            ->whereDoesntHave('rekognitionTasks', fn ($subQuery) => $subQuery
                ->where('job_type', $this->aiObject)
                ->whereNotNull('job_status')
            )
            ->whereHas('service', fn ($serviceQuery) => $serviceQuery
                ->serviceFunctionEnabled($this->aiObject, $type)
            )
            ->distinct('md5')
            ->limit($maxApiJobs)
            ->orderByRaw('md5, created_at')
            ->cursor()
            ->each(function (File $file) {
                if ($file->extension === 'gif' && Rekognition::maxOpenJobsExceeded()) {
                    $this->log('Max open jobs exceeded.', 'warn');

                    return;
                }

                if (Rekognition::shouldSkipDueToDuration($file, $this->aiObject)) {
                    $this->log('Max duration exceeded for file. Object ' . $this->aiObject, 'warn');

                    return;
                }

                $rekognitionTask = $file->rekognitionTasks()->firstOrCreate(
                    ['job_type' => $this->aiObject],
                    ['job_id' => null, 'job_status' => RekognitionJobStatus::PENDING, 'analyzed' => false]
                );

                // NOTE: IMPORTANT
                // Job creating happens when the job_id is set in `saveJobTask` method
                // We stop re-firing jobs if the job_id is already set
                if (filled($rekognitionTask->job_id)) {
                    $this->log('Job already exists.', 'warn');

                    return;
                }

                dispatch(new DispatchRekognitionJob($this->aiObject, $file));
            });
    }
}
