<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Contracts\Filesystem\Filesystem;

class StorageService extends Storage
{
    public static string $bucket;

    private FilesystemManager|Filesystem $storage;

    public function __construct(?Collection $settings = null)
    {
        $settingsArray = $settings?->pluck('payload', 'name')?->toArray();

        if (! empty($settingsArray) &&
            isset(
                $settingsArray['S3_ACCESS_KEY'],
                $settingsArray['S3_SECRET_KEY'],
                $settingsArray['S3_REGION'],
                $settingsArray['S3_BUCKET']
            )
        ) {
            $this->storage = Storage::build([
                'driver'                  => 's3',
                'key'                     => $settingsArray['S3_ACCESS_KEY'],
                'secret'                  => $settingsArray['S3_SECRET_KEY'],
                'region'                  => $settingsArray['S3_REGION'],
                'bucket'                  => $settingsArray['S3_BUCKET'],
                'url'                     => config('filesystems.drivers.s3.url'),
                'endpoint'                => config('filesystems.drivers.s3.endpoint'),
                'use_path_style_endpoint' => config('filesystems.drivers.s3.use_path_style_endpoint', false),
                'throw'                   => false,
            ]);

            self::$bucket = $settingsArray['S3_BUCKET'];
        } else {
            $this->storage = Storage::disk(config('filesystems.default'));
            // both gcp and aws use the same bucket name
            self::$bucket = config('filesystems.disks.s3.bucket');
        }
    }

    public function __call($method, $arguments)
    {
        if (method_exists($this->storage, $method)) {
            return $this->storage->$method(...$arguments);
        }
    }
}
