<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\CustomSource;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use GuzzleHttp\Client;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use Illuminate\Http\RedirectResponse;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;

class CustomSource extends SourceIntegration implements CanPaginate, HasSettings
{
    public Client $client;

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): RedirectResponse
    {
        return to_route('customSource.createService', ['settings' => $settings?->toArray()]);
    }

    public function initialize()
    {
        $this->client = new Client;
    }

    public function paginate(?array $request = []): void
    {
        $this->initialize();

        // CustomSource doesn't have any files to sync
        $this->dispatch([], 'root');
    }

    public function getUser(): ?UserDTO
    {
        return new UserDTO;
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        return new TokenDTO;
    }

    public function uploadThumbnail(mixed $file): string
    {
        return '';
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        return new FileDTO([]);
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        return false;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        return false;
    }

    /**
     * @throws \Throwable
     */
    public function downloadFromService(File $file): StreamedResponse|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');
        throw_unless($file->download_url, CouldNotDownloadFile::class, 'Download URL is not set.');

        $downloadUrl = $file->download_url;

        try {
            $chunkSizeBytes = config('manager.chunk_size', 5) * 1024 * 1024;
            $chunkStart = 0;

            $response =
                response()->streamDownload(function () use (&$chunkStart, $chunkSizeBytes, $downloadUrl) {
                    while (true) {
                        $chunkEnd = $chunkStart + $chunkSizeBytes;

                        try {
                            $response = $this->client->request('GET', $downloadUrl, [
                                'headers' => [
                                    'Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                                ],
                            ]);

                            if ($response->getStatusCode() == 206) {
                                echo $response->getBody()->getContents();
                                $chunkStart = $chunkEnd + 1;
                            } else {
                                break;
                            }
                        } catch (Exception $e) {
                            if ($e->getResponse()->getStatusCode() == 416) {
                                $this->log('File download from service completed');
                            } else {
                                $this->log($e->getMessage(), 'error');
                            }

                            break;
                        }
                    }
                }, $file->name);

            // Set headers for file download
            $response->headers->set('Content-Type', $file->mime_type);
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $file->slug . '.' . $file->extension . '"');
            $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
            $response->headers->set('Pragma', 'public');

            return $response;
        } catch (Exception $e) {
            if ($e->getResponse()->getStatusCode() == 416) {
                $this->log('File download from service completed');
            } else {
                $this->log($e->getMessage(), 'error');
            }
        }

        return false;
    }
}
