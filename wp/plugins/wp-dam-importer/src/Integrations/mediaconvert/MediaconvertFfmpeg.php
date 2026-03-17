<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediaconvert;

use Exception;
use Aws\CommandPool;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\Model;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Services\StorageService;
use MariusCucuruz\DAMImporter\Traits\CleanupTemporaryFiles;

class MediaconvertFfmpeg
{
    use CleanupTemporaryFiles, Loggable;

    private Model $file;

    private string $downloadedFilePath;

    private mixed $ffmpeg;

    public function process(Model $file): bool
    {
        $this->file = $file;

        if ($file->mediaconvertOperation?->status === FileOperationStatus::SUCCESS) {
            $this->log("File {$file->id} is already converted", 'warn');
            $this->endLog();

            return false;
        }

        if ($file->mediaconvertOperation?->status === FileOperationStatus::FAILED) {
            $this->log('File is already in error state for conversion', 'warn');
            $this->endLog();

            return false;
        }

        $this->ffmpeg = config('mediaconvert.binary');
        $this->downloadedFilePath = tempnam(sys_get_temp_dir(), config('mediaconvert.directory'));

        $file->markProcessing(
            FileOperationName::CONVERT,
            'Local FFmpeg conversion started'
        );

        try {
            $response = Http::retry(3, 1000)
                ->timeout(config('queue.timeout'))
                ->sink($this->downloadedFilePath)
                ->get($file->download_url);

            if ($response->failed()) {
                $file->markFailure(
                    FileOperationName::CONVERT,
                    'Failed to download source file for FFmpeg',
                    $response,
                    ['http_status' => $response->status()]
                );

                $this->log('Failed to download file from view_url. HTTP Status: ' . $response->status(), 'error');
                $this->endLog();

                return false;
            }

            if (! $this->run()) {
                $file->markFailure(
                    FileOperationName::CONVERT,
                    'FFmpeg conversion failed'
                );

                return false;
            }
        } catch (Exception $e) {
            $this->log("Error processing mediaconvert file: {$e->getMessage()}", 'error');

            $file->markFailure(
                FileOperationName::CONVERT,
                'Error processing FFmpeg conversion',
                $e->getMessage()
            );

            return false;
        } finally {
            $this->cleanupTemporaryFile($this->downloadedFilePath);
        }
        $file->markSuccess(
            FileOperationName::CONVERT,
            'Local FFmpeg conversion completed successfully'
        );

        return true;
    }

    private function run(): bool
    {
        $mediaconvertTmpDir = Path::join(sys_get_temp_dir(), config('mediaconvert.directory'));
        $tmpDir = "{$mediaconvertTmpDir}/{$this->file->id}/";

        if (! file_exists($tmpDir) && ! mkdir($tmpDir, 0777, true) && ! is_dir($tmpDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $tmpDir));
        }

        if ($this->file->type === 'audio') {
            $remotePath = $this->convertAudio($tmpDir);
        } else {
            $remotePath = $this->convertVideo($tmpDir);
        }

        if (! $remotePath) {
            $this->log('Failed to convert file', 'error');

            return false;
        }

        $this->file->update(['view_url' => $remotePath]);

        return true;
    }

    private function convertVideo(string $tmpDir): string
    {
        [$fileName, $output] = $this->buildOutputPath($tmpDir);
        exec("{$this->ffmpeg} -i \"{$this->downloadedFilePath}\" -c:v libx264 -b:v 4500k -c:a aac -b:a 96k -preset slow -movflags +faststart -y \"{$output}\" 2>&1", $outputLog, $returnCode);

        if ($returnCode !== 0) {
            return '';
        }

        return $this->uploadToStorage($output, $fileName);
    }

    private function convertAudio(string $tmpDir): string
    {
        [$fileName, $output] = $this->buildOutputPath($tmpDir);
        $command = "{$this->ffmpeg} -i \"{$this->downloadedFilePath}\" -vn -acodec libmp3lame -b:a 192k -map 0:a:0 -y \"{$output}\" 2>&1";

        exec($command, $outputLog, $returnCode);

        if ($returnCode !== 0) {
            return '';
        }

        return $this->uploadToStorage($output, $fileName);
    }

    private function uploadToStorage(string $output, string $fileName): string
    {
        $s3Client = StorageService::getClient(); // @phpstan-ignore-line
        $stream = fopen($output, 'rb');
        $remotePath = Path::join(
            config('mediaconvert.directory'),
            $this->file->id,
            $fileName
        );
        $objParams = [
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key'    => $remotePath,
            'Body'   => $stream,
        ];
        $commands[] = $s3Client->getCommand('PutObject', $objParams);
        CommandPool::batch($s3Client, $commands);

        return $remotePath;
    }

    private function buildOutputPath(string $tmpDir): array
    {
        if ($this->file->type === 'audio') {
            $fileName = strtolower($this->file->name) . '-converted-' . time() . '.mp3';
        } else {
            $fileName = strtolower($this->file->name) . '-converted-' . time() . '.mp4';
        }
        $output = $tmpDir . $fileName;

        return [$fileName, $output];
    }
}
