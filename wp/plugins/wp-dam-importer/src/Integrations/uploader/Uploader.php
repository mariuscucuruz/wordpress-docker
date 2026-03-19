<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Uploader;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Services\StorageService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Uploader extends SourceIntegration
{
    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        if ($createThumbnail) {
            $thumbnailPath = $this->uploadThumbnail($file);
        }

        return new FileDTO([
            'parent_id'              => data_get($attr, 'parent_id'),
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => data_get($file, 'id'),
            'md5'                    => data_get($file, 'md5'),
            'name'                   => data_get($file, 'name'),
            'thumbnail'              => $createThumbnail ? $thumbnailPath : data_get($file, 'thumbnail'),
            'mime_type'              => data_get($file, 'mime_type'),
            'extension'              => data_get($file, 'extension'),
            'size'                   => data_get($file, 'size'),
            'duration'               => data_get($file, 'duration'),
            'slug'                   => data_get($file, 'slug'),
            'created_time'           => now()->format('Y-m-d H:i:s'),
            'modified_time'          => now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function uploadThumbnail(mixed $file): string
    {
        return $file['thumbnail'];
    }

    public function downloadFromService(File $file): StreamedResponse|bool
    {
        try {
            $response = response()->streamDownload(function () use ($file) {
                $stream = StorageService::readStream($file->originalDownloadUrl);
                fpassthru($stream);
                fclose($stream);
            }, $file->name);

            $response->headers->set('Content-Type', $file->mime_type);
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $file->name . '.' . $file->extension . '"');
            $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
            $response->headers->set('Pragma', 'public');

            return $response;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return false;
        }
    }

    public function initialize()
    {
        //
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        //
    }

    public function getUser(): ?UserDTO
    {
        return new UserDTO;
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        return new TokenDTO;
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        return false;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        return false;
    }
}
