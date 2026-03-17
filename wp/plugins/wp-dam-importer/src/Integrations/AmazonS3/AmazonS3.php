<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AmazonS3;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Filesystem\Filesystem;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\StoragePackageManager;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Services\StorageService;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;

class AmazonS3 extends StoragePackageManager implements HasSettings, IsTestable
{
    protected Filesystem $driver;

    public function initialize()
    {
        $settings = $this->getSettings();

        if (empty($settings)) {
            $this->driver = StorageService::drive();

            return;
        }

        foreach (array_keys(config('amazons3.settings')) as $setting) {
            throw_if(
                ! isset($settings[$setting]) || $settings[$setting] === '',
                CouldNotInitializePackage::class,
                "{$setting} is required."
            );
        }

        $this->driver = Storage::build([
            'driver'                  => 's3',
            'key'                     => $settings['S3_ACCESS_KEY'],
            'secret'                  => $settings['S3_SECRET_KEY'],
            'region'                  => $settings['S3_REGION'],
            'bucket'                  => $settings['S3_BUCKET'],
            'url'                     => config('filesystems.drivers.s3.url'),
            'endpoint'                => config('filesystems.drivers.s3.endpoint'),
            'use_path_style_endpoint' => config('filesystems.drivers.s3.use_path_style_endpoint', false),
            'throw'                   => false,
        ]);
    }

    public function get(string $path): ?string
    {
        return $this->driver->get($path);
    }

    public function put(File $file, ?string $directory = null): string|bool
    {
        $path = $this->prepareDirectoryStructure($file, $directory);

        $stream = StorageService::readStream($file->originalDownloadUrl); // SAVES THE ORIGINAL FILE

        throw_unless($stream, CouldNotDownloadFile::class, 'Failed to open stream for reading from source');

        $result = $this->driver->writeStream($path, $stream);

        throw_if($result === false, Exception::class, 'Failed to write file to S3');

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $path;
    }

    public function files(?string $directory = null, bool $recursive = false): FileDTO
    {
        $filePaths = $this->driver->files($directory, $recursive);

        return new FileDTO(array_map([$this, 'processFileInfo'], $filePaths));
    }

    public function allFiles(?string $directory = null): FileDTO
    {
        $filePaths = $this->driver->allFiles($directory);

        return new FileDTO(array_map([$this, 'processFileInfo'], $filePaths));
    }

    public function delete(string $path): bool
    {
        throw_unless($this->driver->exists($path), CouldNotDownloadFile::class, 'File does not exist');

        return $this->driver->delete($path) && ! $this->driver->exists($path);
    }

    public function deleteAll(?string $bucket = null, ?string $region = null): bool
    {
        try {
            $settings = $this->getSettings();

            $region = $settings['S3_REGION'] ?? $region ?? config('filesystems.disks.s3.region');
            $bucket = $settings['S3_BUCKET'] ?? $bucket ?? config('filesystems.disks.s3.bucket');

            throw_if(empty($bucket), 'Bucket name is required.');
            throw_if(empty($region), 'Region name is required.');

            $files = $this->driver->allFiles();

            if (empty($files)) {
                return true;
            }

            $this->driver->delete($files);

            return true;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return false;
        }
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Amazon S3 settings are required');

        $requiredSettings = config('amazons3.settings');

        foreach (array_keys($requiredSettings) as $setting) {
            abort_if(! $settings->contains('name', $setting), 406, "Missing required setting: {$setting}");
        }

        $accessKey = $settings->firstWhere('name', 'S3_ACCESS_KEY')?->payload;
        $secretKey = $settings->firstWhere('name', 'S3_SECRET_KEY')?->payload;
        $region = $settings->firstWhere('name', 'S3_REGION')?->payload;
        $bucket = $settings->firstWhere('name', 'S3_BUCKET')?->payload;

        try {
            $s3Client = new S3Client([
                'version'     => 'latest',
                'region'      => $region,
                'credentials' => [
                    'key'    => $accessKey,
                    'secret' => $secretKey,
                ],
            ]);

            abort_if(! $s3Client->doesBucketExist($bucket), 406, "The specified bucket '{$bucket}' does not exist.");

            return true;
        } catch (Exception $e) {
            abort(500, 'Amazon S3 connection test failed: ' . $e->getMessage());
        }
    }

    private function processFileInfo($file)
    {
        return [
            'name'         => $file,
            'size'         => $this->driver->size($file),
            'lastModified' => $this->driver->lastModified($file),
            'extension'    => pathinfo($file, PATHINFO_EXTENSION),
            'mimeType'     => $this->driver->mimeType($file),
            'url'          => $this->driver->url($file),
            'downloadUrl'  => $this->driver->temporaryUrl($file, now()->addSeconds(config('filesystems.ttl') * 60)),
            'visibility'   => $this->driver->visibility($file),
        ];
    }
}
