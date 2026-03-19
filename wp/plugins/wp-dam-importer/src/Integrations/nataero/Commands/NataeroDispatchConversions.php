<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Commands;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Enums\AssetType;
use MariusCucuruz\DAMImporter\Models\AdminSetting;
use MariusCucuruz\DAMImporter\Support\HorizonJobs;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Database\Eloquent\Builder;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\NataeroTask;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroTaskStatus;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroFunctionType;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Enums\NataeroConversionTypes;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Jobs\NataeroConvertDispatchJob;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;

class NataeroDispatchConversions extends Command
{
    use Loggable;

    public int $dispatchCount = 0;

    public const string SIGNATURE = 'nataero:dispatch:conversions';

    protected $signature = self::SIGNATURE
        . ' {--fileID=}'
        . ' {--teamID=}'
        . ' {--serviceID=}'
        . ' {--conversionType=}'
        . ' {--forceOverwrite}'
        . ' {--audio}'
        . ' {--force}'
        . ' {--limit=}';

    protected $description = 'Enqueue asset conversion through the Nataero API.';

    private bool $forceOverwrite;

    public function handle(): int
    {
        $this->startLog();

        if (! AdminSetting::isNataeroConvertEnabled() && ! $this->option('force')) {
            $this->log('Nataero auto dispatch is disabled. Use --force to override.');

            return self::SUCCESS;
        }

        $this->forceOverwrite = (bool) $this->option('forceOverwrite');

        $conversionType = $this->validateConversionTypeOrFail();

        $types = config('nataero.convert_file_types');

        // todo: remove this once it has been fully tested
        if (! AdminSetting::isNataeroFfmpegEnabled()) {
            $types = array_filter($types, fn ($type) => $type !== AssetType::Audio && $type !== AssetType::Video);
        }

        if (empty($types)) {
            $this->log('No file type specified in config/nataero.php for conversion.', 'error');

            return self::FAILURE;
        }

        foreach ($types as $type) {
            $query = $this->fileQuery($type)
                ->limit($this->parseNumber());

            foreach ($query->cursor() as $file) {
                if (! $file->hasSuccessfulDownload() && ! $this->forceOverwrite) {
                    $this->log("Skipping {$file->type} file: {$file->id} - does not meet conversion criteria");

                    continue;
                }

                $this->log("Sending {$file->type} file: {$file->id} to Nataero for conversion");

                $file->markProcessing(
                    FileOperationName::CONVERT,
                    'Queued for Nataero conversion'
                );

                NataeroTask::updateOrCreate(
                    [
                        'file_id'       => $file->id,
                        'function_type' => strtoupper(NataeroFunctionType::CONVERT->value),
                        'version'       => config('nataero.version'),
                    ],
                    ['status' => NataeroTaskStatus::INITIATED->value]
                );

                $this->dispatchCount++;
                dispatch(new NataeroConvertDispatchJob($file, $conversionType));
            }
        }

        $this->log("Dispatched {$this->dispatchCount} " . config('nataero.key') . ' convert jobs.');
        $this->endLog();

        return self::SUCCESS;
    }

    private function validateConversionTypeOrFail(): ?string
    {
        $rawConversionType = $this->option('conversionType');

        if (empty($rawConversionType)) {
            return null;
        }

        $rawConversionType = strtolower((string) $rawConversionType);
        $allowed = array_map(
            fn (NataeroConversionTypes $type) => $type->value,
            NataeroConversionTypes::cases()
        );

        if (! in_array($rawConversionType, $allowed, true)) {
            $message = 'Invalid --conversionType "' . $rawConversionType . '". Allowed values: ' . implode(', ', $allowed);
            $this->log($message, 'error');

            throw new ConsoleInvalidArgumentException($message);
        }

        return $rawConversionType;
    }

    public function fileQuery($type = null): Builder
    {
        $query = File::query()
            ->with('nataeroTasks')
            ->whereNotNull('download_url')
            ->where('extension', '!=', 'gif')
            ->where('type', $type);

        if (! $this->forceOverwrite) {
            $query->whereNull('view_url')
                ->whereHas('operationStates', fn ($q) => $q
                    ->where('operation_name', FileOperationName::DOWNLOAD)
                    ->where('status', FileOperationStatus::SUCCESS))
                ->whereDoesntHave('operationStates', fn ($q) => $q
                    ->where('operation_name', FileOperationName::CONVERT));
        }

        $query->when($fileIDs = $this->option('fileID'), function ($query) use ($fileIDs) {
            $ids = array_filter(explode(',', $fileIDs));
            $query->whereIn('id', $ids);
            $this->log(config('nataero.key') . ' Processing File ID(s): ' . implode(',', $ids));
        });

        $query->when($serviceIDs = $this->option('serviceID'), function ($query) use ($serviceIDs) {
            $query->whereIn('service_id', explode(',', $serviceIDs));
            $this->log(config('nataero.key') . ' Processing Service ID(s): ' . $serviceIDs);
        });

        $query->when($teamIDs = $this->option('teamID'), function ($query) use ($teamIDs) {
            $query->whereIn('team_id', explode(',', $teamIDs));
            $this->log(config('nataero.key') . ' Processing Team ID(s): ' . $teamIDs);
        });

        return $query;
    }

    private function parseNumber(): int
    {
        if ($n = $this->option('limit')) {
            return max((int) $n, 1);
        }

        return HorizonJobs::queueLimit('api');
    }
}
