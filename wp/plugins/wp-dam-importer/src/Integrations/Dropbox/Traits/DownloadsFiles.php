<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Dropbox\Traits;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use GuzzleHttp\Client;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Exception\ClientException;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;

trait DownloadsFiles
{
    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        $thumbnailUrl = data_get($file, 'remote_service_file_id') ?? data_get($file, 'thumbnail') ?? data_get($file, 'metadata.path_lower');
        throw_unless($thumbnailUrl, CouldNotDownloadFile::class, 'File id is not set');

        $accessToken = $this->getAccessToken();
        throw_unless($accessToken, CouldNotGetToken::class, 'Failed to acquire an access token');

        try {
            $fileData = Http::withToken($accessToken)
                ->timeout(30)
                ->connectTimeout(config('queue.timeout', 15))
                ->maxRedirects(10)
                ->withHeaders([
                    'Dropbox-API-Arg' => json_encode(['path' => $thumbnailUrl]),
                    'Connection'      => 'keep-alive',
                    'cache-control'   => 'no-cache',
                ])
                ->withBody('', 'application/octet-stream')
                ->post(config('dropbox.download_url'))
                ->throw()
                ->getBody()
                ->getContents();
        } catch (Exception $e) {
            $this->log("Error downloading to tmp: {$e->getMessage()}", 'error', null, $e->getTrace());

            return false;
        }

        if (empty($fileData)) {
            $this->log("Downloading thumbnail received empty response: {$thumbnailUrl}", 'warn', null);

            return false;
        }

        return $this->storeDataAsFile($fileData, $this->prepareFileName($file));
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        $key = $this->prepareFileName($file);
        $uploadId = $this->createMultipartUpload($key, $file->mime_type);

        $chunkSize = config('manager.chunk_size', 5) * 1048576;
        $chunkStart = 0;
        $partNumber = 1;
        $parts = [];

        try {
            $accessToken = $this->getAccessToken();

            if (empty($accessToken)) {
                throw new CouldNotGetToken('Failed to acquire an access token');
            }

            while (true) {
                $chunkEnd = $chunkStart + $chunkSize;
                $client = new Client([
                    'headers' => [
                        'Authorization'   => "Bearer {$accessToken}",
                        'Dropbox-API-Arg' => json_encode(['path' => $file->remote_service_file_id]),
                        'Range'           => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                    ],
                    'Connection'    => 'keep-alive',
                    'cache-control' => 'no-cache',
                ]);

                $response = $client->request('POST', config('dropbox.download_url'));
                $this->httpStatus = $response->getStatusCode();

                if (in_array($this->httpStatus, [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED])) {
                    return false;
                }

                if (! in_array($this->httpStatus, [Response::HTTP_PARTIAL_CONTENT, Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE])) {
                    throw new CouldNotDownloadFile('File download failed');
                }

                if ($response->getStatusCode() !== Response::HTTP_PARTIAL_CONTENT) {
                    break;
                }

                $parts[] = $this->uploadPart($key, $uploadId, $partNumber++, $response->getBody()->getContents());
                $chunkStart = $chunkEnd + 1;
            }

            return $this->completeMultipartUpload($key, $uploadId, $parts);
        } catch (ClientException $e) {
            $this->httpStatus = $e->getResponse()->getStatusCode();

            if ($e->getResponse()->getStatusCode() === Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
                return $this->completeMultipartUpload($key, $uploadId, $parts);
            }
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return false;
    }

    public function downloadFromService(File $file): StreamedResponse|bool
    {
        if (! $file->remote_service_file_id) {
            throw new CouldNotDownloadFile('File id is not set');
        }

        $accessToken = $this->getAccessToken();

        if (empty($accessToken)) {
            throw new CouldNotGetToken('Failed to acquire an access token');
        }

        $chunkSize = config('manager.chunk_size', 5) * 1048576;
        $chunkStart = 0;

        $response = response()->streamDownload(function () use (&$chunkStart, $chunkSize, $accessToken, $file) {
            while (true) {
                $chunkEnd = $chunkStart + $chunkSize;
                $client = new Client([
                    'headers' => [
                        'Authorization'   => "Bearer {$accessToken}",
                        'Dropbox-API-Arg' => json_encode(['path' => $file->remote_service_file_id]),
                        'Range'           => sprintf('bytes=%s-%s', $chunkStart, $chunkStart + $chunkSize),
                    ],
                    'Connection'    => 'keep-alive',
                    'cache-control' => 'no-cache',
                ]);

                try {
                    $response = $client->request('POST', config('dropbox.download_url'));
                } catch (ClientException $e) {
                    if ($e->getResponse()->getStatusCode() === Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
                        break;
                    }

                    throw new CouldNotDownloadFile('File download failed: ' . $e->getMessage());
                } catch (Exception $e) {
                    $this->log($e->getMessage(), 'error');

                    return null;
                }

                if ($response->getStatusCode() !== Response::HTTP_PARTIAL_CONTENT && $response->getStatusCode() !== Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
                    throw new CouldNotDownloadFile('File download failed');
                }

                if ($response->getStatusCode() !== Response::HTTP_PARTIAL_CONTENT) {
                    break;
                }

                echo $response->getBody()->getContents();
                $chunkStart = $chunkEnd;
            }
        }, $file->name);

        $response->headers->set('Content-Type', $file->mime_type);
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $file->name . '.' . $file->extension . '"');
        $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $response->headers->set('Pragma', 'public');

        return $response;
    }
}
