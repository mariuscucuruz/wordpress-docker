<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Actions;

use MariusCucuruz\DAMImporter\Support\HorizonJobs;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\NataeroTask;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroTaskStatus;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;

class NataeroDispatcher
{
    public function __invoke(
        mixed $file,
        FileOperationName $fileOperationName,
        NataeroFunctionType $nataeroFunctionType,
        string $jobClass
    ): void {
        $file->markProcessing(
            $fileOperationName,
            'Queued for Nataero ' . $fileOperationName->value . ' processing'
        );

        NataeroTask::updateOrCreate(
            [
                'file_id'       => $file->id,
                'function_type' => strtoupper($nataeroFunctionType->value),
                'version'       => config('nataero.version'),
            ],
            ['status' => NataeroTaskStatus::INITIATED->value]
        );

        dispatch(new $jobClass($file));
    }

    public function parseNumber($optionLimit): int
    {
        if ($n = $optionLimit) {
            return max((int) $n, 1);
        }

        return HorizonJobs::queueLimit('api');
    }
}
