<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Metaads\Traits;

use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait HttpClient
{
    protected function downstreamFileFromUrl(File $file, string $downloadUrl): ?StreamedResponse
    {
        $chunkSizeBytes = config('manager.chunk_size', 5) * 1024 * 1024;
        $fileSize = $this->preflightCheckUrl($downloadUrl);

        if ($chunkSizeBytes > $fileSize) {
            $chunkSizeBytes = $fileSize;
        }

        $response = response()->streamDownload(function () use ($chunkSizeBytes, $downloadUrl) {
            $chunkStart = 0;

            while (true) {
                $chunkEnd = $chunkStart + $chunkSizeBytes;

                try {
                    $response = $this->httpRequest()->send('GET', $downloadUrl, [
                        'headers' => [
                            'Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                        ],
                    ]);

                    if (! in_array($response->getStatusCode(), [HttpResponse::HTTP_OK, HttpResponse::HTTP_PARTIAL_CONTENT])) {
                        break;
                    }

                    echo $response->getBody()->getContents();

                    $chunkStart = $chunkEnd + 1;
                } catch (GuzzleException $e) {
                    if ($e->getResponse()?->getStatusCode() === HttpResponse::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
                        $this->log('File download from service completed');
                    } else {
                        $this->log($e->getMessage(), 'error');
                    }

                    break;
                }
            }
        }, $file->name);

        $response->headers->set('Content-Type', $file->mime_type);
        $response->headers->set('Content-Disposition', "attachment; filename={$file->slug}.{$file->extension}");
        $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $response->headers->set('Pragma', 'public');

        return $response;
    }

    protected function preflightCheckUrl(string $downloadUrl): ?int
    {
        $baseUrl = ! str($downloadUrl)->startsWith(['http://', 'https://'])
            ? config('metaads.query_base_url') . config('metaads.version')
            : '/';
        $baseUrl = rtrim($baseUrl, '/');

        $headResponse = Http::head("{$baseUrl}/{$downloadUrl}");

        $fileSize = data_get($headResponse->getHeader('Content-Length'), '0');

        if (! is_numeric($fileSize) || ! $fileSize > 0) {
            $this->log('File has 0 size', 'warn', null, [$downloadUrl, $headResponse->getHeaders()]);

            return null;
        }

        return (int) $fileSize ?? 0;
    }

    protected function httpRequest(
        ?string $token = null,
        ?bool $throwOnError = true,
        ?bool $useVersion = true
    ): PendingRequest {
        $baseUrl = config('metaads.query_base_url') . ($useVersion ? config('metaads.version') : '');

        $request = Http::timeout(config('queue.timeout'))
            ->baseUrl(rtrim($baseUrl, '/'));

        if (filled($token)) {
            $request->withToken($token);
        }

        if (filled($throwOnError) && (bool) $throwOnError) {
            $request->throw();
        }

        return $request;
    }
}
