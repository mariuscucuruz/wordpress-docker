<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Services;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Models\FileOperationState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Traits\UploadsData;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Enums\MediaStorageType;
use MariusCucuruz\DAMImporter\Services\StorageService;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Models\AcrCloudContainer;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Enums\AcrCloudEngineTypes;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Models\AcrCloudMusicTrack;
use MariusCucuruz\DAMImporter\Traits\CleanupTemporaryFiles;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Enums\AcrCloudFileResponseState;

class AcrCloudService
{
    use CleanupTemporaryFiles, Loggable, UploadsData;

    public ?string $appName = 'acrcloud';

    public ?string $baseUrl;

    public ?string $callbackUrl;

    public ?string $bearerToken;

    public ?string $signingToken;

    public ?string $region;

    public ?AcrCloudContainer $acrContainer = null;

    public static function make(): self
    {
        return new self;
    }

    public static function getSettings(?string $key = null)
    {
        $appName = config('acrcloud.name', 'acrcloud');
        $region = config('acrcloud.region');
        $bearerToken = config('acrcloud.bearer_token');
        $signingToken = config('acrcloud.signing_token');
        $callbackUrl = config('acrcloud.callback_url');
        $baseUrl = config('acrcloud.acr_base_url');
        $settings = compact('appName', 'region', 'bearerToken', 'signingToken', 'callbackUrl', 'baseUrl');

        return filled($key) ? data_get($settings, $key) : $settings;
    }

    public function __construct()
    {
        $this->initialize();
        $this->resolveAcrContainer();
    }

    private function initialize(): void
    {
        $settings = static::getSettings();
        $this->region = data_get($settings, 'region');
        $this->appName = data_get($settings, 'appName', 'acrcloud');
        $this->baseUrl = data_get($settings, 'baseUrl');
        $this->bearerToken = data_get($settings, 'bearerToken');
        $this->signingToken = data_get($settings, 'signingToken');
        $this->callbackUrl = $this->buildCallbackUrl(data_get($settings, 'callbackUrl'));
    }

    private function buildCallbackUrl(?string $baseCallbackUrl): ?string
    {
        if (empty($baseCallbackUrl)) {
            return null;
        }

        if (empty($this->signingToken)) {
            return $baseCallbackUrl;
        }

        // Strip fragment - webhook callbacks are server-to-server, fragments are meaningless
        $url = strtok($baseCallbackUrl, '#');
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'token=' . urlencode($this->signingToken);
    }

    public function resolveAcrContainer(string|int|null $containerId = null): ?AcrCloudContainer
    {
        if ($containerId) {
            return $this->acrContainer = AcrCloudContainer::where('acr_container_id', $containerId)->first() ?? null;
        }

        if (filled($this->acrContainer)) {
            return $this->acrContainer;
        }

        if (empty($this->bearerToken) || empty($this->baseUrl) || empty($this->callbackUrl)) {
            $this->initialize();
        }

        try {
            return DB::transaction(function () {
                $acrContainer = AcrCloudContainer::lockForUpdate()->first();

                if ($acrContainer) {
                    return $this->acrContainer = $acrContainer;
                }

                $name = (app()->isProduction() ? $this->appName : 'test') . '-' . str()->random(6);
                $payload = [
                    'name'         => $name,
                    'region'       => $this->region,
                    'buckets'      => ['ACRCloud Music'],
                    'engine'       => AcrCloudEngineTypes::AUDIO_FINGERPRINTING,
                    'audio_type'   => 'recorded',
                    'policy'       => ['type' => 'traverse', 'interval' => 0, 'rec_length' => 10],
                    'callback_url' => $this->callbackUrl,
                ];

                $response = Http::withToken($this->bearerToken)
                    ->acceptJson()
                    ->asJson()
                    ->post(Path::join($this->baseUrl, 'fs-containers'), $payload)
                    ->throw();

                return $this->acrContainer = AcrCloudContainer::create([
                    'acr_container_id' => $response->json('data.id'),
                    'name'             => $name,
                ]);
            });
        } catch (Exception $e) {
            $this->log("Failed to resolve ACR Container: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return null;
    }

    public function uploadAudioToAcrCloud(File $file): void
    {
        $localPath ??= $this->getLocalPath($file);
        throw_unless($localPath, 'Failed to download audio file');

        $attachment = fopen($localPath, 'rb');
        throw_unless($attachment, 'Failed opening audio file to upload to ACR.');

        if (empty($this->bearerToken) || empty($this->baseUrl)) {
            $this->initialize();
            $this->resolveAcrContainer();
        }

        try {
            $response = Http::timeout(config('queue.timeout'))
                ->withToken($this->bearerToken)
                ->acceptJson()
                ->attach('file', $attachment, "{$file->id}.mp3")
                ->post(Path::join($this->baseUrl, 'fs-containers', $this->acrContainer->acr_container_id, 'files'), [
                    'data_type'    => FunctionsType::Audio->value,
                    'callback_url' => $this->callbackUrl,
                ])->throw();

            if ($response->failed()) {
                $this->log("Failed to upload audio to ACR Container. Status Code: {$response->status()}", 'error');

                return;
            }
        } catch (Exception $e) {
            $this->log("Upload to ACR Cloud failed: {$e->getMessage()}", 'error', null, $e->getTrace());

            return;
        } finally {
            $this->cleanupTemporaryFile($localPath);
        }

        $file->markProcessing(
            FileOperationName::ACRCLOUD,
            'Uploaded to ACRCloud',
            [
                'remote_task_id'   => data_get($response, 'data.id'),
                'acr_container_id' => $this->acrContainer->acr_container_id,
            ]
        );
    }

    public function getFileResult(FileOperationState $fileOperationState): ?bool
    {
        $remoteTaskId = $fileOperationState->remote_task_id;
        $containerId = $this->acrContainer?->acr_container_id;
        $file = $fileOperationState->file;

        if (empty($remoteTaskId) || empty($containerId)) {
            $file->markFailure(
                FileOperationName::ACRCLOUD,
                'Missing remote_task_id on state',
                'Cannot query file result without remote_task_id',
                ['previous_state_id' => $fileOperationState->id]
            );
            $this->log('Cannot query file result without remote_task_id', 'error', null, [
                'fileOperationState' => $fileOperationState->id,
                'file_id'            => $fileOperationState->file_id,
            ]);

            return false;
        }

        try {
            $body = Http::maxRedirects(10)
                ->timeout(config('queue.timeout', 30))
                ->withToken($this->bearerToken)
                ->withUserAgent('Medialake API Client for ACR Cloud/1.0')
                ->acceptJson()
                ->get(Path::join($this->baseUrl, 'fs-containers', $containerId, 'files', $remoteTaskId))
                ->throw()
                ->json();

            $responseState = AcrCloudFileResponseState::makeFromInt(data_get($body, 'data.0.state'));
            $musicResults = (array) data_get($body, 'data.0.results.music', []);

            if (empty($musicResults) && $responseState === AcrCloudFileResponseState::NO_RESULTS) {
                $this->uploadData($file, $body);

                $file->markSuccess(
                    FileOperationName::ACRCLOUD,
                    'ACRCloud completed with no results',
                    [
                        'remote_task_id'   => $remoteTaskId,
                        'acr_container_id' => $containerId,
                        'response'         => $body,
                        'response_state'   => $responseState,
                    ]
                );

                return true;
            }

            if (empty($musicResults)) {
                $this->log('ACR Cloud results not ready yet');

                return null;
            }

            foreach ($musicResults as $musicTrack) {
                $this->saveMusicTrackForFile($musicTrack, $file->id);
            }

            $this->uploadData($file, $body);

            $file->markSuccess(
                FileOperationName::ACRCLOUD,
                'ACRCloud result retrieved',
                [
                    'remote_task_id'   => $remoteTaskId,
                    'acr_container_id' => $containerId,
                    'response'         => $body,
                    'response_state'   => $responseState,
                ]
            );

            return true;
        } catch (Exception $e) {
            $file->markFailure(
                FileOperationName::ACRCLOUD,
                $e->getMessage(),
                $e->getTraceAsString(),
            );

            $this->log("Could not get ACR Cloud result: {$e->getMessage()}", 'error', null, $e->getTrace());

            return false;
        }
    }

    public function processWebhookResultForFile(File $file, array $webhookData): void
    {
        $musicResults = (array) data_get($webhookData, 'music', []);

        foreach ($musicResults as $musicTrack) {
            $this->saveMusicTrackForFile($musicTrack, $file->id);
        }

        rescue(
            fn () => $this->uploadData($file, $webhookData),
            fn (Exception $e) => $this->log($e->getMessage(), 'error', null, $e->getTrace())
        );
    }

    public function getLocalPath(File $file): ?string
    {
        $tempFilePath = tempnam(sys_get_temp_dir(), strtolower("{$file->type}_{$this->appName}_{$file->id}"));
        throw_unless($tempFilePath, CouldNotDownloadFile::class, 'Failed to create temporary file!');

        $fp = fopen($tempFilePath, 'wb');
        throw_unless($fp, CouldNotDownloadFile::class, 'Cannot write to temp!');

        if (! str_starts_with($file->original_view_url, Path::forStorage(MediaStorageType::derivatives->value))) {
            return null;
        }

        $s3Client = app(StorageService::class)->getClient();
        $s3Client?->getObject([
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key'    => $file->original_view_url,
            'SaveAs' => $fp,
        ]);

        $pathWithExtension = $tempFilePath . config("mediaconvert.output_extensions.{$file->type}");

        rename($tempFilePath, $pathWithExtension);

        return file_exists($pathWithExtension) ? $pathWithExtension : $tempFilePath;
    }

    protected function saveMusicTrackForFile(array $musicTrack, string $fileId): void
    {
        $artists = collect(data_get($musicTrack, 'result.artists', []))
            ->pluck('name')
            ->implode(',');

        AcrCloudMusicTrack::create([
            'file_id'    => $fileId,
            'title'      => data_get($musicTrack, 'result.title'),
            'artists'    => $artists,
            'album'      => data_get($musicTrack, 'result.album.name'),
            'label'      => data_get($musicTrack, 'result.label'),
            'start_time' => data_get($musicTrack, 'offset'),
            'duration'   => data_get($musicTrack, 'played_duration'),
            'isrc'       => data_get($musicTrack, 'result.external_ids.isrc'),
            'score'      => data_get($musicTrack, 'result.score'),
        ]);
    }
}
