<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Exif;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\DateSupport;
use Illuminate\Support\Facades\Process;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Traits\UploadsData;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Traits\FileInformation;
use MariusCucuruz\DAMImporter\Interfaces\CanUpload;
use MariusCucuruz\DAMImporter\Interfaces\PackageTypes\IsFunction;

class Exif implements CanUpload, IsFunction
{
    use FileInformation, Loggable, UploadsData;

    public $exifTool;

    public function __construct()
    {
        $this->exifTool = config('exif.tool');
    }

    public static function searchableData(File $file)
    {
        // Read from new JSON metadata store instead of legacy exifs table
        $exifData = collect($file->exifMetadata?->data ?? []);

        $transformedKeys = collect(Models\ExifMetadata::searchableKeys())->mapWithKeys(fn ($key) => [
            (string) str($key)->slug('_') => $exifData->get($key),
        ]);

        return $transformedKeys->filter()->toArray();
    }

    public function process(File $file, ?string $tmpFile = null): bool
    {
        if (! is_executable($this->exifTool)) {
            $this->log(class_basename($this) . ' binary not found at ' . $this->exifTool, 'error');

            return false;
        }

        $this->startLog();

        $result = rescue(function () use ($tmpFile, $file) {
            $fileToProcess = $tmpFile ?? $file->download_url ?? null;

            if (! $fileToProcess) {
                return false;
            }

            $command = with($fileToProcess, fn ($filePath) => $tmpFile
                ? "{$this->exifTool} {$filePath} -fast"
                : 'curl -s --max-time ' . config('queue.timeout') .
                " --connect-timeout 10 '{$filePath}' | {$this->exifTool} -fast -"
            );

            $process = Process::timeout(config('queue.timeout'))->run($command);

            if ($process->failed()) {
                $exitCode = $process->exitCode();
                $msg = match (true) {
                    in_array($exitCode, [137, 143], true) => __CLASS__ . ' timed out after ' . config('queue.timeout') . ' seconds',
                    $exitCode === 28                      => __CLASS__ . ' curl connection timed out',
                    default                               => trim($process->errorOutput() ?: $process->output() ?: __CLASS__ . ' failed without output'),
                };

                $this->log(__CLASS__ . " process failed for file {$file->id}: {$msg}", 'error', context: [
                    'file_id'   => $file->id,
                    'exit_code' => $exitCode,
                    'command'   => $command,
                ]);

                return false;
            }
            $output = $process->output();

            return $this->saveExifData($output, $file);
        }, function ($exception) {
            $this->log($exception->getMessage(), 'error');

            return false;
        });

        $this->endLog();

        if (is_bool($result)) {
            return $result;
        }

        return false;
    }

    public function handleDurationInput($value): float|int|null
    {
        $totalMilliseconds = null;

        if (substr_count($value, ':') === 2) {
            // 00:00:15
            [$hours, $minutes, $seconds] = explode(':', $value);
            $totalMilliseconds = ($hours * 3600 * 1000) + ($minutes * 60 * 1000) + ($seconds * 1000);
        } elseif (preg_match('~([0-9\.]+)~', (string) $value, $matches) !== false) {
            // 15.04 s, 15.04 s (approx), -0.01 s (approx)
            $totalMilliseconds = (float) $matches[0] * 1000;
        } else {
            $this->log('Unhandled duration format from EXIF data: ' . $value, 'warn');
        }

        return $totalMilliseconds;
    }

    public function saveExifData(string|array $output, File $file): bool
    {
        if (is_array($output)) {
            $output = $output[0] ?? '';
        }

        $collection = collect(explode("\n", trim($output)))
            ->map(fn ($item) => collect(explode(' : ', $item))
                ->map(fn ($item) => filled($item) ? trim($item) : null));

        $version = $collection->shift()[1] ?? null;

        if (! isset($version)) {
            $this->log("Cannot get the Exif version from ({$collection->shift()})", 'warn');

            return false;
        }

        $data = [];
        $aggregated = [];

        $collection->each(function ($item) use ($file, &$data, &$aggregated) {
            if ($item && isset($item[0], $item[1])) {
                $key = $item[0];
                $value = $item[1];

                $key = iconv('UTF-8', 'UTF-8//IGNORE', $key);
                $value = iconv('UTF-8', 'UTF-8//IGNORE', $value);

                if (
                    ! str_contains($key, 'History')
                    && ! str_contains($key, 'Ingredients')
                    && ! str_contains($key, 'Pantry')
                ) {
                    $data[] = compact('key', 'value');
                }

                // Always aggregate into JSON metadata (excluding heavy keys)
                if (
                    ! str_contains($key, 'History')
                    && ! str_contains($key, 'Ingredients')
                    && ! str_contains($key, 'Pantry')
                ) {
                    $aggregated[$key] = $value;
                }

                if (! $file->duration && $file->type === FunctionsType::Video->value && $key == 'Duration') {
                    $totalMilliseconds = $this->handleDurationInput($value);

                    if ((is_int($totalMilliseconds) || is_float($totalMilliseconds)) && $totalMilliseconds > 0) {
                        $file->duration = (int) ((float) $totalMilliseconds);
                    }
                }

                if ($key === 'Media Data Size' && empty($file->size)) {
                    $file->size = (int) $value;
                }

                if ($key === 'Video Frame Rate' && empty($file->fps)) {
                    $file->fps = (float) $value;
                }

                if ($key === 'Image Size' && empty($file->resolution)) {
                    $file->resolution = $value;
                }

                if ($key === 'Create Date' && empty($file->created_time) && DateSupport::isValidTimestamp($value)) {
                    $file->created_time = $value;
                }

                if ($key === 'Modify Date' && empty($file->modified_time) && DateSupport::isValidTimestamp($value)) {
                    $file->modified_time = $value;
                }

                if ($key === 'File Type Extension' && empty($file->extension)) {
                    $file->extension = $value;

                    if (empty($file->type)) {
                        $file->type = $this->getFileTypeFromExtension($value);
                    }

                    $file->download_url = $this->ensureUrlHasExtension($file->original_download_url, $file->extension);
                    $file->view_url = $this->ensureUrlHasExtension($file->original_view_url, $file->extension);

                    $file->type = $this->getFileTypeFromExtension($file->extension);
                }

                if ($key === 'MIME Type' && empty($file->mime_type)) {
                    $file->mime_type = $value;
                }
            }
        });

        $file->save();

        // Upsert JSON metadata (new single-row store)
        if (! empty($aggregated)) {
            $file->exifMetadata()->updateOrCreate(
                ['file_id' => $file->id],
                ['version' => $version, 'data' => $aggregated]
            );
        } else {
            $this->log("No EXIF data to aggregate for file ID: ({$file->id})", 'info');
        }

        $this->log('Uploading ' . __CLASS__ . "data for file ID: ({$file->id})", icon: '📤');
        $this->uploadData($file, $data);

        return true;
    }
}
