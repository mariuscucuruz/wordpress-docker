<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Traits\UploadsData;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Services\AcrCloudService;
use MariusCucuruz\DAMImporter\Interfaces\CanUpload;
use MariusCucuruz\DAMImporter\Traits\CleanupTemporaryFiles;
use MariusCucuruz\DAMImporter\Interfaces\PackageTypes\IsFunction;

class AcrCloud implements CanUpload, HasSettings, IsFunction
{
    use CleanupTemporaryFiles,
        Loggable,
        UploadsData;

    private AcrCloudService $service;

    public function __construct()
    {
        $this->service = AcrCloudService::make();
    }

    public function getSettings(): array
    {
        return AcrCloudService::getSettings();
    }

    public function process(File $file): bool
    {
        $file->markProcessing(
            FileOperationName::ACRCLOUD,
            'ACRCloud job started processing'
        );

        $cannotProcess = match (true) {
            ! FunctionsType::acrCanProcess($file->type)                 => 'File is not a video or audio',
            ! $file->hasSuccessfulOperation(FileOperationName::CONVERT) => 'File is not ready for processing',
            empty($file->view_url)                                      => 'File has not been converted or does not have download UR',

            default => null,
        };

        if (filled($cannotProcess)) {
            $this->log("{$cannotProcess}. File ID: {$file->id}", 'warn', null, $file->toArray());

            return false;
        }

        try {
            $this->service->uploadAudioToAcrCloud($file);

            return true;
        } catch (Exception $e) {
            $this->log("Failed to upload to ACR Cloud: {$e->getMessage()}", 'error');
        }

        return false;
    }
}
