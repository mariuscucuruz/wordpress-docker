<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediaconvert;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use Aws\Exception\AwsException;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use Aws\MediaConvert\MediaConvertClient;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Templates\DefaultSettings;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotCompleteFunction;
use MariusCucuruz\DAMImporter\Interfaces\PackageTypes\IsFunction;

class Mediaconvert implements IsFunction
{
    use Loggable;

    public MediaConvertClient $mediaconvertClient;

    public ?DefaultSettings $settings = null;

    public function __construct()
    {
        $awsConfig = [
            'region'   => config('mediaconvert.region'),
            'version'  => config('mediaconvert.version'),
            'endpoint' => config('mediaconvert.endpoint'),
        ];

        if (! config('app.disable_aws_credentials')) {
            $awsConfig['credentials'] = config('rekognition.credentials');
        }

        $this->mediaconvertClient = new MediaConvertClient($awsConfig);
    }

    public function process(File $file, ?string $tmpFile = null, array $settingOverrides = []): bool
    {
        $this->startLog();

        throw_unless($file->name, CouldNotCompleteFunction::class, 'File name is missing');
        throw_unless($file->download_url, CouldNotCompleteFunction::class, 'File download url is missing');

        $operation = $file->mediaconvertOperation;

        if ($operation && in_array($operation->status, [
            FileOperationStatus::SUCCESS,
            FileOperationStatus::FAILED,
            FileOperationStatus::PROCESSING,
        ], true)) {
            $this->log("File {$file->id} already has a CONVERT operation ({$operation->status->value}), skipping.");
            $this->endLog();

            return false;
        }

        $this->settings = new DefaultSettings($file, $settingOverrides);

        if ($job = $this->createJobQueue($file)) {
            $jobId = $job['Id'] ?? null;
            $jobStatus = $job['Status'] ?? null;

            $file->markProcessing(
                FileOperationName::CONVERT,
                "MediaConvert job created (status: {$jobStatus})",
                [
                    'remote_task_id' => $jobId,
                    'aws_status'     => $jobStatus,
                    'settings'       => $job['Settings']['OutputGroups'][0]['Outputs'][0],
                ]
            );

            $this->log("Created MediaConvert job for file {$file->id} (job_id: {$jobId})", icon: '🚀');

            $this->endLog();

            return true;
        }

        $file->markFailure(
            FileOperationName::CONVERT,
            'Failed to create AWS MediaConvert job'
        );

        $this->endLog();

        return false;
    }

    public function handleFailure(File $file, ?Exception $exception = null): void
    {
        if (filled($exception)) {
            $retryable = is_retryable_aws_error($exception);

            $file->markOperation(
                FileOperationName::CONVERT,
                $retryable ? FileOperationStatus::PROCESSING : FileOperationStatus::FAILED,
                $retryable ? '' : 'The failure is not retryable',
                $retryable ? null : ['reason' => 'non_retryable'],
                $exception?->getMessage(),
            );

            $this->log(
                text: $exception->getMessage(),
                level: $retryable ? 'warning' : 'error',
                context: [
                    'error' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString(),
                ]
            );
        }
    }

    public function getJobById($Id)
    {
        return $this->mediaconvertClient->getJob(compact('Id'));
    }

    protected function createJobQueue(File $file)
    {
        return rescue(fn () => retry(
            times: config('mediaconvert.retry_on_failure', 3),
            callback: function () {
                $queue = $this->createJob($this->settings->toArray());

                if ($queue && isset($queue['Job'])) {
                    return $queue['Job'];
                }

                return [];
            },
            sleepMilliseconds: fn ($attempt) => (2 ** $attempt) * config('mediaconvert.retry_in_milliseconds'),
            when: fn ($e) => is_retryable_aws_error($e)
        ), function (AwsException $exception) use ($file) {
            $this->handleFailure($file, $exception);

            return [];
        });
    }

    protected function getJobTemplate(string $name)
    {
        return $this->mediaconvertClient->getJobTemplate(['Name' => $name]);
    }

    protected function createJob($setting)
    {
        try {
            return $this->mediaconvertClient->createJob([
                'Role'     => config('mediaconvert.role'),
                'Settings' => $setting,
            ]);
        } catch (AwsException $e) {
            $this->log($e->getMessage(), 'error');
        }

        return false;
    }

    protected function getSetting($setting, $file)
    {
        $setting['Inputs'][0]['FileInput'] = 's3://'
            . Path::join(
                config('mediaconvert.bucket'),
                config('manager.directory.derivatives'),
                $file->id,
                (string) $file
            );

        return $setting;
    }

    protected function getJobTemplates()
    {
        $result = $this->mediaconvertClient->ListJobTemplates([
            'Role'   => config('aws.role'),
            'ListBy' => 'NAME',
        ]);

        return $result ?: [];
    }
}
