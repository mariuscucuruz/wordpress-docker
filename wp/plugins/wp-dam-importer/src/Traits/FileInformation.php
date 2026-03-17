<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use Exception;
use Illuminate\Support\Facades\Http;

trait FileInformation
{
    public function getFileSize(string $key): ?int
    {
        if (! $this->storage->exists($key)) {
            return null;
        }

        return $this->storage->size($key);
    }

    public function getFileExtensionFromFileName(string|int|null $fileName): ?string
    {
        if (empty($fileName)) {
            return null;
        }

        return is_string(pathinfo((string) $fileName, PATHINFO_EXTENSION))
            ->lower()
            ->before('?')
            ->toString();
    }

    public function getFileTypeFromExtension(?string $extension): ?string
    {
        $extensionLower = str($extension)->lower()->toString();

        return match (true) {
            in_array($extensionLower, config('manager.meta.image_extensions'))    => 'image',
            in_array($extensionLower, config('manager.meta.audio_extensions'))    => 'audio',
            in_array($extensionLower, config('manager.meta.video_extensions'))    => 'video',
            in_array($extensionLower, config('manager.meta.document_extensions')) => 'pdf',

            default => null,
        };
    }

    public function getFileExtensionFromRemoteUrl(?string $url = null): ?string
    {
        if (empty($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        try {
            $response = Http::head($url)->throw();
            $mimeType = $response->header('Content-Type');

            return $this->getMimeTypeOrExtension($mimeType);
        } catch (Exception $e) {
            $this->log('Error getting file extension: ' . $e->getMessage()); // @phpstan-ignore-line
        }

        return null;
    }

    public function ensureUrlHasExtension(?string $url, ?string $extension): ?string
    {
        if (empty($url) || empty($extension)) {
            return $url;
        }

        $basename = basename($url);
        $dirname = dirname($url);

        if (! str_contains($basename, '.') || str_ends_with($basename, '.')) {
            return rtrim($dirname, '/') . '/' . rtrim($basename, '.') . '.' . $extension;
        }

        return $url;
    }

    public function getMimeTypeOrExtension(?string $mimeTypeOrExtension): ?string
    {
        if (empty($mimeTypeOrExtension)) {
            return null;
        }

        $mapping = config('manager.extensions_and_mime_types');

        return $mapping[$mimeTypeOrExtension]
            ?? array_flip($mapping)[$mimeTypeOrExtension]
            ?? null;
    }

    public static function isExtensionSupported(?string $extension = null): bool
    {
        if (empty($extension)) {
            return false;
        }

        return in_array($extension, config('manager.meta.file_extensions'));
    }
}
