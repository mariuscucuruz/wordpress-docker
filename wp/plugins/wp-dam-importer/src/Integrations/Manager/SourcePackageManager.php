<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Manager;

use Exception;
use Throwable;
use InvalidArgumentException;
use RuntimeException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Http\File as FileSystem;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\DTOs\IntegrationDefinition;
use MariusCucuruz\DAMImporter\Enums\MediaStorageType;
use MariusCucuruz\DAMImporter\Traits\FileInformation;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\Interfaces\PackageTypes\IsSource;
use MariusCucuruz\DAMImporter\Pagination\Paginates;

abstract class SourcePackageManager extends Manager implements IsSource
{
    use FileInformation,
        Paginates;

    public ?string $displayName = null;

    public int|string|null $httpStatus = null;

    private mixed $filePointer = null;

    private ?string $tempFilePath = null;

    abstract public static function definition(): IntegrationDefinition;

    public static function isGoogleRelated(Service $service): bool
    {
        return in_array($service->name, ['youtube', 'googledrive']);
    }

    public static function loadConfiguration(?array $customKeys = null): array
    {
        $configKey = static::getServiceName();
        $serviceConfig = config($configKey, []);

        if (empty($serviceConfig)) {
            throw new InvalidSettingValue("Invalid configuration values for {$configKey}.");
        }

        if (empty($customKeys)) {
            return $serviceConfig;
        }

        $configs = [];

        foreach ($customKeys as $configKey) {
            $configs[] = $serviceConfig[$configKey];
        }

        return $configs;
    }

    public function getStoragePath(string $fileName, string $storage = MediaStorageType::originals->value): string
    {
        $root = Path::forStorage($storage);

        if (str_starts_with($fileName, $root . DIRECTORY_SEPARATOR)) {
            return dirname($fileName);
        }

        if (str_contains($fileName, DIRECTORY_SEPARATOR)) {
            return Path::join($root, dirname($fileName));
        }

        return $root;
    }

    public function storeDataAsFile(string $fileData, string $fileName, string $storage = MediaStorageType::originals->value): ?string
    {
        $storagePath = $this->getStoragePath($fileName, $storage);
        $tempFilePath = $this->tmpFileResource();

        try {
            $tmpFile = fopen($tempFilePath, 'wb');
            fwrite($tmpFile, $fileData);

            return $this->storage->putFileAs(
                path: $storagePath,
                file: new FileSystem($tempFilePath),
                name: basename($fileName)
            );
        } catch (Exception $e) {
            $context = compact('tempFilePath', 'fileName', 'storage');
            logger()->error($e, array_merge($context, $e->getTrace()));
        } finally {
            $this->cleanupTemporaryFile($tempFilePath, $tmpFile ?? null);
        }

        return null;
    }

    public function downstreamToTmpFile(?string $data = null, ?string $fileName = null, ?bool $saveAndClose = false): string|bool
    {
        if (empty($data) && empty($fileName)) {
            return false;
        }

        if (empty($data) && ! empty($fileName)) {
            $filePath = $this->storage->putFileAs(
                path: $this->getStoragePath($fileName),
                file: new FileSystem($this->tmpFileResource()),
                name: basename($fileName)
            );

            $this->cleanupTemporaryFile($this->tmpFileResource(), $this->filePointer);

            return $filePath;
        }

        if (! empty($data)) {
            try {
                $this->tmpFileResource();
                fwrite($this->filePointer, $data);

                return true;
            } catch (Exception $e) {
                logger()->error("Error writing to tmp: {$e}", $e->getTrace());
                $this->cleanupTemporaryFile($this->tmpFileResource(true), $this->filePointer);

                return false;
            }
        }

        return false;
    }

    protected function tmpFileResource(?bool $saveAndClose = false): ?string
    {
        $filePointerAlreadyInUse = $this->filePointer && is_resource($this->filePointer);

        if ($saveAndClose && ! $filePointerAlreadyInUse) {
            return null;
        }

        if ($saveAndClose && $filePointerAlreadyInUse) {
            $this->cleanupTemporaryFile($this->tempFilePath, $this->filePointer);

            $this->filePointer = null;
            $this->tempFilePath = null;

            return null;
        }

        if ($filePointerAlreadyInUse) {
            return $this->tempFilePath;
        }

        $currentServiceName = config(self::getServiceName() . '.name', self::getServiceName());

        $this->tempFilePath = tempnam(sys_get_temp_dir(), "{$currentServiceName}_");
        throw_unless($this->tempFilePath, CouldNotDownloadFile::class, "Temporary file not found: {$this->tempFilePath}");

        $this->filePointer = fopen($this->tempFilePath, 'w');

        return $this->tempFilePath;
    }

    public function isUrlValid(?string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        try {
            $res = Http::timeout(15)
                ->retry(2, 200)
                ->withHeaders([
                    'Accept' => '*/*',
                    'Range'  => 'bytes=0-0',
                ])
                ->get($url);

            return ($res->status() >= 200 && $res->status() < 400)
                || $res->status() === 206;
        } catch (Throwable $e) {
            $this->log("URL validation error: {$e->getMessage()}", 'error');

            return false;
        }
    }

    public function filterSupportedFileExtensions(array $files, string $extensionKey = 'extension'): array
    {
        return collect($files)
            ->filter(fn ($file) => $this->isExtensionSupported(data_get($file, $extensionKey)))
            ->values()
            ->toArray();
    }

    // Extract and return specific metadata attributes from the source file array.
    // By default, this method returns the entire array without filtering.
    // Override this method to customize the selection of metadata attributes.

    public function getMetadataAttributes(?array $properties): array
    {
        return $properties;
    }

    public function hasMethod(?string $methodName = null): bool
    {
        return method_exists($this, $methodName);
    }

    public function generateRedirectOauthState($extraData = []): string
    {
        $state = config('manager.oauth_class')::encryptedMlToken();

        if (isset($this->settings) && $this->settings->count()) {
            $state['settings'] = $this->settings->pluck('id')?->toArray();
        }

        if (! empty($extraData)) {
            $state = array_merge($state, $extraData);
        }

        return json_encode($state, JSON_THROW_ON_ERROR);
    }

    public function streamServiceFileToTempFile(string $downloadUrl): string
    {
        $tempPath = $this->tmpFileResource();

        $response = Http::maxRedirects(10)
            ->timeout(config('queue.timeout'))
            ->withUserAgent(config('medialake.user_agent'))
            ->accept('*/*')
            ->withOptions(['sink' => $tempPath])
            ->get($downloadUrl);

        if ($response->failed() || ! is_file($tempPath) || filesize($tempPath) === 0) {
            $this->cleanupTemporaryFile($tempPath);

            throw new RuntimeException('Failed to stream delivered file from CDN URL.');
        }

        return $tempPath;
    }

    public function handleDownloadFromService(File $file, string $downloadUrl, array $headers = []): StreamedResponse|bool
    {
        throw_unless(! empty($downloadUrl), CouldNotDownloadFile::class, 'Download URL is not set.');

        try {
            $request = Http::maxRedirects(10)
                ->timeout(config('queue.timeout'))
                ->withUserAgent(config('medialake.user_agent'))
                ->accept('*/*')
                ->withOptions(['stream' => true]);

            if (! empty($headers)) {
                $request = $request->withHeaders($headers);
            }

            $response = $request->get($downloadUrl);

            if ($response->failed()) {
                $this->log("Failed to fetch file from URL: {$downloadUrl}, Status: {$response->status()}", 'error');

                return false;
            }

            $filename = $this->buildDownloadFilename($file);
            $body = $response->getBody();

            return response()->streamDownload(function () use ($body) {
                while (! $body->eof()) {
                    echo $body->read(8192);
                    flush();
                }
            }, $filename, [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            ]);
        } catch (Throwable $e) {
            $this->log("Error downloading file. Error: {$e->getMessage()}", 'error');

            return false;
        }
    }

    public function buildDownloadFilename(File $file): string
    {
        $name = trim($file->name ?: (string) $file->id);
        $extension = ltrim((string) $file->extension, '.');

        return trim("{$name}.{$extension}", '.');
    }

    public function downloadWithYtDlp(?string $url = null, ?bool $retry = false): ?string
    {
        if (empty($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid URL provided: {$url}.");
        }

        $tempFilePath = tempnam(sys_get_temp_dir(), self::getServiceName());

        if (! file_exists($tempFilePath) || ! is_writable($tempFilePath)) {
            throw new InvalidArgumentException('Cannot write to TMP directory.');
        }

        // remove $tempFilePath to prevent error in yt_dlp
        $this->cleanupTemporaryFile($tempFilePath);

        $args = collect([
            config('manager.yt_dlp', '/opt/homebrew/bin/yt-dlp'),
            '--quiet',
            '--no-warnings',
            '--no-progress',
            '--no-cache-dir',
            '--no-part',
            '-o',
            $tempFilePath,
            $url,
        ])
            ->when(app()->hasDebugModeEnabled(), fn (Collection $args) => $args->push(
                '--progress',
                '--print-traffic',
            ));

        $process = Process::timeout(config('queue.timeout', 3600))
            ->run($args->toArray());

        if ($process->failed()) {
            $this->log("YTDLP Error [{$process->exitCode()}]: {$process->errorOutput()}", 'error', null, compact('url', 'tempFilePath'));

            if ($process->seeInErrorOutput('Unable to extract webpage video data') && ! $retry) {
                return $this->downloadWithYtDlp($tempFilePath, $url, true);
            }

            return null;
        }

        return $tempFilePath;
    }

    protected function filterDisabledQueries(array $metadata = []): array
    {
        return array_filter($metadata, fn (array $folder) => ! empty($folder['status']));
    }

    public function cleanupTemporaryFile(?string $tempFilePath = null, $fp = null): void
    {
        if (empty($tempFilePath)) {
            return;
        }

        ignore_user_abort(true);

        if (! empty($fp) && is_resource($fp)) {
            fclose($fp);
            $this->log("Closed file pointer: {$tempFilePath}");
        }

        if (! file_exists($tempFilePath)) {
            return;
        }

        rescue(function () use ($tempFilePath) {
            retry(
                times: [100, 200, 300], // Sleep 100ms on 1st attempt, 200ms on 2nd, 300ms on 3rd so 3 times
                callback: function () use ($tempFilePath) {
                    clearstatcache(true, $tempFilePath);

                    if (! is_writable($tempFilePath)) {
                        $this->log("Temporary file is not writable: {$tempFilePath}", 'error');

                        return false;
                    }

                    if (is_dir($tempFilePath)) {
                        $this->log("Temporary file is a directory: {$tempFilePath}", 'error');

                        return false;
                    }

                    if (! is_file($tempFilePath)) {
                        $this->log("Temporary file is not a file: {$tempFilePath}", 'error');

                        return false;
                    }

                    if (unlink($tempFilePath)) {
                        $this->log("Deleted temporary file: {$tempFilePath}");

                        return true;
                    }

                    $this->log("Failed to delete temporary file: {$tempFilePath}", 'error');

                    return false;
                }
            );
        }, function (Exception $e) use ($tempFilePath) {
            $this->log("Failed to delete temporary file: {$tempFilePath} after multiple attempts: {$e->getMessage()}", 'warn');
        });
    }

}
