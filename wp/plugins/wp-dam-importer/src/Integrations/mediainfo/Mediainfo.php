<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediainfo;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use RuntimeException;
use Illuminate\Support\Facades\Process;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Traits\UploadsData;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Interfaces\CanUpload;
use MariusCucuruz\DAMImporter\Interfaces\PackageTypes\IsFunction;

class Mediainfo implements CanUpload, IsFunction
{
    use Loggable, UploadsData;

    public $mediainfo;

    public function __construct()
    {
        $this->mediainfo = config('mediainfo.command');
    }

    public function process(File $file, ?string $tmpFile = null): bool
    {
        if (! is_executable($this->mediainfo)) {
            $this->log(class_basename($this) . ' binary not found at ' . $this->mediainfo, 'error');

            return false;
        }

        $this->startLog();

        try {
            $fileToProcess = $tmpFile ?? $file->download_url ?? null;

            throw_unless($fileToProcess, 'File not found');

            $localFilePath = with($fileToProcess, function ($filePath) use ($tmpFile, $file) {
                if (empty($tmpFile)) {
                    $localFilePath = '/tmp/' . $file->id . '.' . $file->extension;

                    $download = Process::timeout(config('queue.timeout'))->run(
                        'curl -s --max-time ' . config('queue.timeout') .
                        ' --connect-timeout 10 -o ' . escapeshellarg($localFilePath) . ' ' . escapeshellarg($filePath)
                    );

                    if ($download->exitCode() === 28) {
                        $this->log(__CLASS__ . " curl connection timed out for file {$file->id}", 'error');
                    }

                    if ($download->failed() || ! is_file($localFilePath) || filesize($localFilePath) === 0) {
                        $this->log(__CLASS__ . " download failed or empty for file {$file->id}", 'error', context: [
                            'exit_code' => $download->exitCode(),
                            'command'   => $download->command(),
                        ]);

                        if (is_file($localFilePath)) {
                            @unlink($localFilePath);
                        }

                        return false;
                    }

                    return $localFilePath;
                }

                return $filePath;
            });

            if ($localFilePath === false) {
                return false;
            }

            $command = "{$this->mediainfo} --Output=JSON " . escapeshellarg($localFilePath);
            $process = Process::timeout(config('queue.timeout'))->run($command);

            if ($process->failed()) {
                $exitCode = $process->exitCode();
                $msg = in_array($exitCode, [137, 143], true)
                    ? __CLASS__ . ' timed out after ' . config('queue.timeout') . ' seconds'
                    : trim($process->errorOutput() ?: $process->output() ?: __CLASS__ . ' failed without output');

                $this->log(__CLASS__ . " process failed for file {$file->id}: {$msg}", 'error', context: [
                    'file_id'   => $file->id,
                    'exit_code' => $process->exitCode(),
                    'command'   => $command,
                ]);

                if (is_file($localFilePath)) {
                    @unlink($localFilePath);
                }

                return false;
            }

            $output = $process->output();
            $data = self::saveMediainfoOutput($output, $file);

            if ($localFilePath && file_exists($localFilePath)) {
                unlink($localFilePath);
            }
            $this->log('Uploading ' . __CLASS__ . "data for file ID: ({$file->id})", icon: '📤');
            $this->uploadData($file, $data);
            $result = true;
        } catch (Exception $exception) {
            $this->log($exception->getMessage(), 'error');

            $result = false;
        }

        $this->endLog();

        return $result;
    }

    public static function saveMediainfoOutput(string|array $output, File $file): array
    {
        if (is_array($output)) {
            $mediaInfo = $output;
        } else {
            $mediaInfo = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }

        if (! $file->duration && $file->type === FunctionsType::Video->value && data_get($mediaInfo, 'media.track.0.Duration')) {
            $duration = (int) ((float) data_get($mediaInfo, 'media.track.0.Duration') * 1000);

            if ($duration > 0) {
                $file->duration = $duration;
            }
        }

        $version = $mediaInfo['creatingLibrary']['version'] ?? null;

        $generalInfo = data_get($mediaInfo, 'media.track.0', []);
        $videoInfo = data_get($mediaInfo, 'media.track.1', []);
        $audioInfo = data_get($mediaInfo, 'media.track.2', []);

        $information = [...$generalInfo, ...$videoInfo, ...$audioInfo];

        $data = [];

        if (empty($information)) {
            throw new RuntimeException('Cannot get the any MediaInfo data');
        }

        $width = '';
        $height = '';

        foreach ($information as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $key = str_replace('_', '', str()->limit($key, 255));

            $data[$key] = $value;

            if (is_string($value)) {
                $value = iconv('UTF-8', 'UTF-8//IGNORE', $value);
            }

            // Deprecated: Prevent writing to legacy mediainfos table to avoid bloat. Will be removed in a future release.
            // $file->mediainfos()->updateOrCreate([
            //     'mediainfoable_id'   => $file->id,
            //     'mediainfoable_type' => $file::class,
            //     'key'                => $key,
            // ], compact('version', 'value'));

            switch ($key) {
                case 'DataSize':
                    if (empty($file->size)) {
                        $file->size = $value;
                    }

                    break;
                case 'FrameRate':
                    if (empty($file->fps)) {
                        $file->fps = $value;
                    }

                    break;
                case 'Width':
                    $width = $value;

                    break;
                case 'Height':
                    $height = $value;

                    break;
            }

            if (! $file->resolution && $width && $height) {
                $file->resolution = "{$width}x{$height}";
            }
        }

        $file->save();

        // Upsert JSON metadata (new single-row store)
        $file->mediainfoMetadata()->updateOrCreate(
            ['file_id' => $file->id],
            ['version' => $version, 'data' => $data]
        );

        return $data;
    }
}
