<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Traits;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Service\WebSweepService;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Models\WebSweepCrawlItem;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

trait HasDownloads
{
    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_if(empty($file->remote_service_file_id), CouldNotDownloadFile::class, 'File id is not set.');

        $crawledItem = WebSweepService::fetchCrawledItem($file->remote_service_file_id);

        throw_if(empty($crawledItem?->url), CouldNotDownloadFile::class, "Download URL not provided. File ID: {$file->id}");

        $key = $this->handleTemporaryDownload($file, $crawledItem?->url);

        if (! $key) {
            return false;
        }

        if ($size = $this->getFileSize($key)) {
            $file->update(['size' => $size]);
        }

        return $key;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $key = $this->prepareFileName($file);
        $uploadId = $this->createMultipartUpload($key, $file->mime_type);
        throw_unless($uploadId, CouldNotDownloadFile::class, 'Failed to initialize multi-part upload');

        $chunkSize = config('manager.chunk_size', 5) * 1024 * 1024;
        $chunkStart = 0;
        $partNumber = 1;
        $parts = [];

        try {
            while (true) {
                $chunkEnd = $chunkStart + $chunkSize;
                $response = Http::timeout(config('queue.timeout'))->withHeaders([
                    'Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                ])->get($file->remote_service_file_id);

                if ($response->status() !== 206) {
                    break;
                }

                $parts[] = $this->uploadPart($key, $uploadId, $partNumber++, $response->body());
                $chunkStart = $chunkEnd + 1;
            }

            $key = $this->completeMultipartUpload($key, $uploadId, $parts);
            $fileSize = $this->getFileSize($key);
            $file->update(['size' => $fileSize]);

            return $key;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return false;
        }
    }

    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        $fileMeta = (array) $file->getMetaExtra('source_link');

        $downloadUrl = data_get($fileMeta, 'source_link')
            ?? data_get($fileMeta, 'media_url')
            ?? WebSweepCrawlItem::query()->firstWhere('id', $file->remote_service_file_id)?->url
            ?? $file->download_url;

        try {
            $tempPath = $this->streamServiceFileToTempFile($downloadUrl);
        } catch (Exception $e) {
            $this->log("Download from service failed: {$e->getMessage()}", 'error', null, $e->getTrace());

            return false;
        }

        $response = new BinaryFileResponse($tempPath, SymfonyResponse::HTTP_OK, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
        ]);

        $response
            ->setContentDisposition('attachment', $this->filenameForFile($file))
            ->deleteFileAfterSend();

        return $response;
    }

    private function filenameForFile(File $file): string
    {
        $filename = $file->name ?? $file->slug ?? $file->id;

        throw_if(empty($filename), CouldNotDownloadFile::class, 'File name is not set.');

        $extension = $file->extension
            ?? $this->getMimeTypeOrExtension($file->mime_type)
            ?? $this->getFileExtensionFromFileName($file->download_url)
            ?? $this->getFileExtensionFromRemoteUrl($file->download_url);

        throw_if(empty($extension), CouldNotDownloadFile::class, 'File extension is not set.');

        return trim(strtolower("{$filename}.{$extension}"));
    }
}
