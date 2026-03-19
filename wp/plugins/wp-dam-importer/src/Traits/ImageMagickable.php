<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use Imagick;
use Exception;
use Throwable;
use ImagickPixel;
use MariusCucuruz\DAMImporter\Support\Path;
use ImagickException;
use Illuminate\Support\Number;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use Clickonmedia\Manager\Traits\Loggable;
use Clickonmedia\Manager\Services\StorageService;
use Illuminate\Support\Facades\File as FileFacade;
use Clickonmedia\Manager\Traits\CleanupTemporaryFiles;

trait ImageMagickable
{
    use CleanupTemporaryFiles, Loggable;

    public array $temporaryFiles = [];

    public function validateImageFile(): bool
    {
        if (! in_array($this->file->extension, config('manager.meta.image_extensions'))) {
            $this->log("Non-image extension file: {$this->file->extension} detected. Wrong job type. File ID: {$this->file->id}", 'error');

            return false;
        }

        return $this->validateFile();
    }

    public function validateDocumentFile(): bool
    {
        if (! in_array($this->file->extension, config('manager.meta.document_extensions'))) {
            $this->log("Non-document extension file: {$this->file->extension} detected. Wrong job type. File ID: {$this->file->id}", 'error');

            return false;
        }

        return $this->validateFile();
    }

    public function validateFile(): bool
    {
        if (! $this->file->name || ! $this->file->slug) {
            $this->log("File name is missing. File ID: {$this->file->id}", 'error');

            return false;
        }

        if (empty($this->file->download_url)) {
            $this->log("File download url is missing. File ID: {$this->file->id}");
            $this->file->operationStates()
                ->where('operation_name', FileOperationName::CONVERT)
                ->latest()
                ->first()
                ?->delete();

            return false;
        }

        return true;
    }

    public function cleanUpFiles(): void
    {
        foreach ($this->temporaryFiles as $tempFile) {
            $this->cleanupTemporaryFile($tempFile);
        }

        $this->temporaryFiles = [];
    }

    public function handleFailure(string $message, Throwable|Exception|null $exception = null): void
    {
        $errorMessage = $exception?->getMessage() ?? '';

        $this->log(text: $message, level: 'error', icon: '❌', context: [
            'file_id' => $this->file->id,
            'error'   => $errorMessage,
            'trace'   => $exception?->getTraceAsString() ?? '',
        ]);

        $this->file->markFailure(
            FileOperationName::CONVERT,
            $message,
            $errorMessage,
        );
    }

    // NOTE: FRAGILE - DO NOT TOUCH THIS METHOD UNTIL I REFACTORED IT
    public function convertImage(bool $generateThumbnail = false): ?string
    {
        $outputExtension = 'jpg';
        $maxQuality = config('manager.image_max_quality', 70);
        $minQuality = config('manager.image_min_quality', 10);
        $maxThumbnailResolution = config('manager.max_thumbnail_resolution', 300);

        $maxSize = $generateThumbnail
            ? config('manager.max_thumbnail_size', 50 * 1024) // 50 KB
            : config('manager.max_image_size', 15 * 1024 * 1024); // 15 MB

        $minDimension = config('manager.min_image_dimensions', 80);
        $maxWidth = $generateThumbnail ? $maxThumbnailResolution : min(config('manager.max_image_dimensions', 10000), 10000);
        $maxHeight = $generateThumbnail ? $maxThumbnailResolution : min(config('manager.max_image_dimensions', 10000), 10000);
        // $maxPPEDimension = config('manager.max_image_ppe_dimensions', 4096);

        try {
            $tempDir = sys_get_temp_dir();
            throw_unless(is_writable($tempDir), "Temporary directory is not writable: {$tempDir}");
            $tmpFilePath = tempnam($tempDir, 'imagick_temp_');

            $fp = fopen($tmpFilePath, 'wb');
            $stream = fopen($this->file->download_url, 'rb');

            // READING FILE IN CHUNKS TO AVOID LOADING THE ENTIRE FILE IN MEMORY
            while (! feof($stream)) {
                fwrite($fp, fread($stream, config('manager.chunk_size') * 1024 * 1024));
            }
            fclose($stream);
            fclose($fp);
            $imagick = new Imagick;
            $imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 4096);
            $imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 4096);
            $imagick->readImage($tmpFilePath);

            if ($imagick->getImageColorspace() !== Imagick::COLORSPACE_SRGB) {
                $this->log('Converting from ' . $imagick->getImageColorspace() . ' to sRGB to avoid inversion');
                $imagick->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }

            // Check file size and dimensions BEFORE modifying the image
            $imageWidth = $imagick->getImageWidth();
            $imageHeight = $imagick->getImageHeight();
            $this->log("Initial image dimensions: {$imageWidth}x{$imageHeight}");
            $fileSize = Number::fileSize(filesize($tmpFilePath));  // Get the actual size of the saved image
            $rawFileSize = filesize($tmpFilePath); // Get raw file size in bytes
            $this->log("Initial file size: {$fileSize}");

            if ($generateThumbnail) {
                $this->log('Generating thumbnail using thumbnailImage function...');
                $imagick->thumbnailImage(960, 540, true); // Maintaining aspect ratio
            } elseif ($imageWidth > $maxWidth || $imageHeight > $maxHeight) {
                // Calculate a proportional scale factor to fit within 10,000px
                $resizeFactor = 10000 / max($imageWidth, $imageHeight);
                $newWidth = (int) ($imageWidth * $resizeFactor);
                $newHeight = (int) ($imageHeight * $resizeFactor);

                $this->log("Resizing to fit Rekognition limits: {$newWidth}x{$newHeight}");
                $imagick->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1, true);
                $imagick->writeImage($tmpFilePath);
            }

            // FORCE THE IMAGE TO BE A PROGRESSIVE JPEG (REDUCES FILE SIZE)
            $this->log('Setting to INTERLACE_PLANE');
            $imagick->setInterlaceScheme(Imagick::INTERLACE_PLANE);
            $this->log('Setting to COMPRESSION_JPEG');
            $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
            $this->log("Setting quality to maximum {$maxQuality}%");
            $imagick->setImageCompressionQuality($maxQuality);

            // HANDLE ALPHA CHANNEL
            if ($imagick->getImageAlphaChannel()) {
                $this->log('Image has an alpha channel. Removing...', 'warn');
                $imagick->setBackgroundColor(new ImagickPixel('white'));
                $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }

            $this->log("Setting image format to {$outputExtension}");
            $imagick->setImageFormat($outputExtension);

            clearstatcache(true, $tmpFilePath);
            $fileSize = filesize($tmpFilePath);

            // Gradually reduce quality if the file exceeds max size
            while ($rawFileSize > $maxSize && $maxQuality > $minQuality) {
                $this->log("Reducing quality to fit size limit. Current quality: {$maxQuality}", 'warn');
                $imagick->setImageCompressionQuality($maxQuality -= 5);
                $imagick->stripImage(); // Remove metadata to save space
                $imagick->writeImage($tmpFilePath);
                clearstatcache(true, $tmpFilePath);
                $rawFileSize = filesize($tmpFilePath);
                $this->log('Current file size after compression: ' . Number::fileSize($rawFileSize));
            }
            // Note: get the new dimensions after resizing
            $imageWidth = $imagick->getImageWidth();
            $imageHeight = $imagick->getImageHeight();
            $this->log("image dimensions after reducing quality: {$imageWidth}x{$imageHeight}");

            // Aggressively resize if quality adjustment did not reduce enough
            while ($fileSize > $maxSize && ($imageWidth > $minDimension && $imageHeight > $minDimension)) {
                $this->log('File is still too large, resizing further...', 'warn');
                $imageWidth = (int) ($imageWidth * 0.75); // Reduce by 25%
                $imageHeight = (int) ($imageHeight * 0.75);

                if ($imageWidth < $minDimension || $imageHeight < $minDimension) {
                    $this->log("Image is smaller than the minimum dimension of {$minDimension}", 'warn');

                    break;
                }

                $this->log("Resizing to: {$imageWidth}x{$imageHeight}");
                $imagick->resizeImage($imageWidth, $imageHeight, Imagick::FILTER_LANCZOS, 1, true);
                $imagick->writeImage($tmpFilePath);
                clearstatcache(true, $tmpFilePath);
                $fileSize = filesize($tmpFilePath);
                $this->log("File size after resizing: {$fileSize}");
            }

            $imageWidth = $imagick->getImageWidth();
            $imageHeight = $imagick->getImageHeight();
            $this->log('image dimensions after resizing: ' . $imageWidth . 'x' . $imageHeight);

            // ENSURE THE IMAGE IS NOT SMALLER THAN THE MINIMUM DIMENSIONS
            if ($imageWidth < $minDimension || $imageHeight < $minDimension) {
                $this->log("Image is smaller than the minimum dimension of {$minDimension}. Scaling up...", 'warn');
                $scaleFactor = $minDimension / min($imageWidth, $imageHeight); // Scale proportionally
                $newWidth = (int) ($imageWidth * $scaleFactor);
                $newHeight = (int) ($imageHeight * $scaleFactor);
                $imagick->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1, true);
            }

            // PPE RESIZING CHECK
            // if ($imageWidth > $maxPPEDimension || $imageHeight > $maxPPEDimension) {
            //     $this->log("Image exceeds maximum PPE dimension: {$maxPPEDimension}.", 'warn');
            //     $imagick->resizeImage($maxPPEDimension, $maxPPEDimension, Imagick::FILTER_LANCZOS, 1, true);
            // }

            // CHOOSE IMAGE NAME
            $imageName = ($generateThumbnail ? 'thumbnail' : 'converted')
                . '-' . str($this->file->slug)->limit(20, '')->toString() . '.' . $outputExtension;

            $rootPath = $generateThumbnail
                ? config('manager.directory.thumbnails')
                : config('manager.directory.derivatives');

            $targetFile = Path::join($rootPath, $this->file->id, $imageName);
            // ensure correct colors
            $imagick->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $imagick->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            $imagick->writeImage($tmpFilePath);

            StorageService::put($targetFile, FileFacade::get($tmpFilePath));

            if ($generateThumbnail) {
                $this->file->thumbnail = $targetFile;
            } else {
                $this->file->view_url = $targetFile;
            }

            $this->file->save();

            $this->file->importGroup?->increment('number_of_files_processed');
            $this->file->importGroup?->parent?->increment('number_of_files_processed');

            $this->concludedLog(($generateThumbnail ? 'Thumbnail generated' : 'Image converted') . ' successfully');

            if (! $generateThumbnail && empty($this->file->thumbnail)) {
                $this->log('Now generating thumbnail...');
                $this->convertImage(generateThumbnail: true);
            }

            return $targetFile;
        } catch (ImagickException|Exception $e) {
            $this->handleFailure('Failed to process the image', $e);

            return null;
        } finally {
            if (isset($imagick)) {
                $imagick->clear();
                unset($imagick);
            }
            $this->cleanupTemporaryFile($tmpFilePath ?? null);
        }
    }
}
