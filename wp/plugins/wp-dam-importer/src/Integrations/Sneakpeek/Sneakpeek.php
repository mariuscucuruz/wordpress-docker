<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sneakpeek;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use Aws\CommandPool;
use MariusCucuruz\DAMImporter\Support\Path;
use RuntimeException;
use Carbon\CarbonInterval;
use MariusCucuruz\DAMImporter\Support\PresignedUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\Sneakpeek\DTOs\SneakpeekDTO;
use MariusCucuruz\DAMImporter\Services\StorageService;
use MariusCucuruz\DAMImporter\Traits\CleanupTemporaryFiles;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotCompleteFunction;
use MariusCucuruz\DAMImporter\Integrations\Sneakpeek\Models\Sneakpeek as SneakpeekModel;
use MariusCucuruz\DAMImporter\Interfaces\PackageTypes\IsFunction;

class Sneakpeek implements IsFunction
{
    use CleanupTemporaryFiles, Loggable;

    public function uploadToS3(array|object $item): void
    {
        if (! is_iterable($item)) {
            return;
        }

        try {
            $s3Client = StorageService::getClient(); // @phpstan-ignore-line
            $commands = [];

            $tmpPath = data_get($item, 'tmp_path', '');
            $remotePath = data_get($item, 'remote_path', '');

            $stream = fopen($tmpPath, 'rb');
            $objParams = [
                'Bucket' => config('filesystems.disks.s3.bucket'),
                'Key'    => $remotePath,
                'Body'   => $stream,
            ];

            $commands[] = $s3Client->getCommand('PutObject', $objParams);
            CommandPool::batch($s3Client, $commands);

            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function getProbeData(string $filePath, string $type)
    {
        try {
            $ffprobe = config('sneakpeek.ffprobe');

            throw_unless($ffprobe, "Config value for 'ffprobe' not found");

            // TODO: remove if not using these:
            // $ffmpeg = config('sneakpeek.ffmpeg');
            // $scaleWidth = config('sneakpeek.single_width');
            // $scaleHeight = config('sneakpeek.single_height');

            if ($type === 'resolution') {
                $cmd = "{$ffprobe} -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 \"{$filePath}\"";
            } elseif ($type === 'duration') {
                $cmd = "{$ffprobe} -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"{$filePath}\"";
            } else {
                return 0;
            }

            $process = Process::timeout(config('queue.timeout'))->run($cmd);

            if ($process->failed()) {
                $this->log($process->errorOutput(), 'error');
            }

            return $process->output();
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return 0;
    }

    /**
     * @throws Throwable
     */
    public function getDuration(string $filePath): float
    {
        return (float) $this->getProbeData($filePath, 'duration');
    }

    /**
     * @throws Throwable
     */
    public function getResolution(string $filePath): string
    {
        return $this->getProbeData($filePath, 'resolution');
    }

    public function generateSingleThumbnail(File $file, string $filePath, float $duration): void
    {
        try {
            $ffmpeg = config('sneakpeek.ffmpeg');
            $scaleWidth = config('sneakpeek.single_width');
            $scaleHeight = config('sneakpeek.single_height');

            $sneakpeekTmpDir = Path::join(sys_get_temp_dir(), config('manager.directory.sneakpeeks'));
            $tmpDir = "{$sneakpeekTmpDir}/{$file->id}/thumbnail/";

            // Delete a directory if it already exists
            $this->deleteDir($tmpDir);

            if (! file_exists($tmpDir) && ! mkdir($tmpDir, 0777, true) && ! is_dir($tmpDir)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $tmpDir));
            }

            $output = $tmpDir . strtolower($file->id) . '-sneakpeek-thumb-' . $scaleWidth . '-' . time() . '.jpg';

            $middleFrame = ceil($duration / 2);
            $interval = CarbonInterval::seconds($middleFrame)->cascade();
            $timecode = sprintf('%02d:%02d:%02d', $interval->hours, $interval->minutes, $interval->seconds);

            $threads = max(1, (int) (shell_exec('nproc') / 2));
            $command = "{$ffmpeg} -i {$filePath} -ss {$timecode} -vframes 1 -vf \"scale=w={$scaleWidth}:h={$scaleHeight}:force_original_aspect_ratio=decrease,pad={$scaleWidth}:{$scaleHeight}:(ow-iw)/2:(oh-ih)/2:black\" -threads {$threads} -f image2 {$output} 2>&1";

            $process = Process::timeout(config('queue.timeout'))->run($command);

            if ($process->failed()) {
                logger()->error($process->errorOutput());
            }

            $filename = basename($output);

            $result = [
                'tmp_path'    => $output,
                'remote_path' => Path::join(
                    config('manager.directory.sneakpeeks'),
                    $file->id,
                    $filename
                ),
            ];

            $this->uploadToS3($result);

            $file->thumbnail = $result['remote_path'];
            $file->save();
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function generateImageSprite(File $file, string $filePath, float $duration): void
    {
        try {
            $ffmpeg = config('sneakpeek.ffmpeg');
            $imageCount = config('sneakpeek.sprite_image_count');
            $fps = "{$imageCount}/{$duration}"; // 50/10 = 5 fps
            $scaleWidth = (int) config('sneakpeek.scale');
            $scaleHeight = (int) round($scaleWidth * (9 / 16)); // 16:9 aspect ratio
            $sneakpeekTmpDir = Path::join(sys_get_temp_dir(), config('manager.directory.sneakpeeks'));

            $tmpDir = "{$sneakpeekTmpDir}/{$file->id}/sneakpeek/";

            // Delete a directory if it already exists
            $this->deleteDir($tmpDir);

            if (! file_exists($tmpDir) && ! mkdir($tmpDir, 0777, true) && ! is_dir($tmpDir)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $tmpDir));
            }

            $output = $tmpDir . strtolower($file->id) . '-' . $scaleWidth . '-' . time() . '.jpg';

            $threads = max(1, (int) (shell_exec('nproc') / 2));
            $command =
                "{$ffmpeg} -i {$filePath} -threads {$threads} -vf fps={$fps},scale={$scaleWidth}:ih*{$scaleWidth}/iw,crop={$scaleWidth}:{$scaleHeight},setsar=1,tile=1x50 {$output} 2>&1";
            $run = Process::timeout(config('queue.timeout'))->run($command);

            if ($run->failed()) {
                logger()->error($run->errorOutput());
            }

            $counter = str_contains($fps, '/')
                ? (int) (explode('/', $fps)[1] ?? 1)
                : (int) $fps;

            $resolution = $this->getResolution($filePath);
            $filename = basename($output);

            $dto = new SneakpeekDTO([
                'object_url'  => $this->objectUrl($filename, $file->id),
                'remote_path' => Path::join(
                    config('manager.directory.sneakpeeks'),
                    $file->id,
                    $filename
                ),
                'fps'                 => $fps,
                'timestamp'           => $counter,
                'original_resolution' => $resolution,
                'width'               => $scaleWidth,
                'path'                => $output,
            ]);

            $sneakpeek = $dto->toArray();
            $sneakpeek['sneakpeekable_type'] = get_class($file);
            $sneakpeek['sneakpeekable_id'] = $file->id;

            $this->uploadToS3($sneakpeek);
            SneakpeekModel::insert($sneakpeek);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function process(File $file, ?string $tmpFile = null): bool
    {
        throw_unless(
            $file->type == 'video',
            CouldNotCompleteFunction::class,
            "Attempted to run Sneakpeek job on an image. File ID: {$file->id}",
            'error'
        );

        if (! $file->view_url) {
            $this->log("File has not been converted. File ID: {$file->id}", 'error');

            return false;
        }

        $tmpFilePath = tempnam(sys_get_temp_dir(), config('manager.directory.sneakpeeks'));

        try {
            $response = Http::retry(3, 1000)
                ->timeout(config('queue.timeout'))
                ->sink($tmpFilePath)
                ->get($file->view_url);

            if ($response->failed()) {
                $this->log("Failed to download file from view_url. HTTP Status: {$response->status()}", 'error');

                return false;
            }

            $duration = $this->getDuration($tmpFilePath);

            $this->generateSingleThumbnail($file, $tmpFilePath, $duration);
            $this->generateImageSprite($file, $tmpFilePath, $duration);

            return true;
        } catch (Exception $e) {
            $this->log("Error processing sneakpeek file: {$e->getMessage()}", 'error');

            return false;
        } finally {
            $this->deleteDir(Path::join(config('manager.directory.sneakpeeks'), $file->id));
            $this->deleteDir(Path::join(config('manager.directory.sneakpeeks')));
            $this->cleanupTemporaryFile($tmpFilePath);
        }
    }

    private function deleteDir($dirPath): void
    {
        if (! is_dir($dirPath)) {
            return;
        }

        if (! str_ends_with($dirPath, DIRECTORY_SEPARATOR)) {
            $dirPath .= DIRECTORY_SEPARATOR;
        }

        $files = glob($dirPath . '*', GLOB_MARK);

        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->deleteDir($file);
            } else {
                unlink($file);
            }
        }

        rmdir($dirPath);
    }

    private function remotePath($tmp_path)
    {
        return str_replace(sys_get_temp_dir() . DIRECTORY_SEPARATOR, '', $tmp_path);
    }

    private function objectUrl($tmp_path, $fileId)
    {
        $domain = 'https://' . config('filesystems.disks.s3.bucket') . '.s3.' . config('filesystems.disks.s3.region') . '.amazonaws.com/';

        return $domain . Path::join(
            config('manager.directory.sneakpeeks'),
            $fileId,
            $this->remotePath($tmp_path)
        );
    }

    public function saveSneakpeekData($results, $file): void
    {
        try {
            $file->thumbnail = $results['thumbnail_destination'];
            $file->save();

            $domain = 'https://' . config('filesystems.disks.s3.bucket') . '.s3.' . config('filesystems.disks.s3.region') . '.amazonaws.com';
            $dto = new SneakpeekDTO([
                'object_url'          => $domain . $results['sprite_destination'],
                'remote_path'         => $results['sprite_destination'],
                'fps'                 => $results['fps'],
                'timestamp'           => $results['timestamp'],
                'original_resolution' => $results['original_resolution'],
                'width'               => $results['width'],
                'path'                => $results['sprite_path'],
            ]);

            $sneakpeek = $dto->toArray();
            $sneakpeek['sneakpeekable_type'] = get_class($file);
            $sneakpeek['sneakpeekable_id'] = $file->id;

            SneakpeekModel::insert($sneakpeek);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public static function generatePresignedUrl(File $file, $fileName, int $hoursUntilExpiry): string
    {
        $derivativeKey = Path::join(
            config('manager.directory.sneakpeeks'),
            $file->id,
            $fileName
        );

        return PresignedUrl::putUrl($derivativeKey, $hoursUntilExpiry);
    }
}
