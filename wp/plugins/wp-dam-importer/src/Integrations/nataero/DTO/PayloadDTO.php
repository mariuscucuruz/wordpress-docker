<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\DTO;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Enums\AssetType;
use MariusCucuruz\DAMImporter\Support\PresignedUrl;
use InvalidArgumentException;
use MariusCucuruz\DAMImporter\Integrations\Sneakpeek\Sneakpeek;

class PayloadDTO
{
    public function __construct(
        public string $remote_file_id,
        public string $source,
        public string $webhook_url,
        public string $operation,
        public array $outputs,
        public string $service_url,
        public ?string $webhook_signing_secret = null,
        public ?string $client_name = null,
        public ?string $details = null,
        public ?string $file_type = null,
    ) {
        $this->client_name ??= config('app.name');
        $this->webhook_signing_secret ??= config('nataero.webhook_signing_secret');
    }

    public function serviceUrl(): string
    {
        return rtrim($this->service_url, '/');
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function convertPayload(File $file, int $hoursUntilExpiry, ?string $conversionType = null): PayloadDTO
    {
        $viewOutput = self::viewOutput($file, $hoursUntilExpiry);
        $thumbOutput = self::thumbOutput($file, $hoursUntilExpiry);

        $operation = match ($file->type) {
            AssetType::PDF->value => 'pdf',
            AssetType::Audio->value, AssetType::Video->value => 'ffmpeg',
            default => 'imagemagick',
        };

        $outputs = match ($file->type) {
            AssetType::PDF->value   => self::pdfPayload($file, $hoursUntilExpiry, $viewOutput, $thumbOutput),
            AssetType::Image->value => self::imagePayload($conversionType, $viewOutput, $thumbOutput),
            default                 => [$viewOutput],
        };

        return new self(
            remote_file_id: $file->id,
            source: temporary_url($file->originalDownloadUrl),
            webhook_url: config('nataero.convert_webhook_url'),
            operation: $operation,
            outputs: $outputs,
            service_url: config('nataero.query_base_url') . '/services/convert/',
            file_type: $file->type ?? 'any',
        );
    }

    public static function sneakpeekPayload(File $file, $hoursUntilExpiry = 8): PayloadDTO
    {
        $spriteUrlPut = Sneakpeek::generatePresignedUrl($file, "{$file->id}.jpg", $hoursUntilExpiry);
        $thumbPut = Sneakpeek::generatePresignedUrl($file, "{$file->id}_thumb.jpg", $hoursUntilExpiry);

        $viewOutput = [
            'name'               => 'view_url',
            'sprite_destination' => $spriteUrlPut,
            'config'             => config('sneakpeek'),
        ];

        $thumbOutput = [
            'name'                  => 'thumbnail',
            'thumbnail_destination' => $thumbPut,
            'config'                => config('sneakpeek'),
        ];
        $outputs = [
            'sprite'    => $viewOutput,
            'thumbnail' => $thumbOutput,
        ];

        return new self(
            remote_file_id: $file->id,
            source: temporary_url($file->originalDownloadUrl),
            webhook_url: config('nataero.sneakpeek_webhook_url'),
            operation: 'sneakpeek',
            outputs: $outputs,
            service_url: config('nataero.query_base_url') . '/services/sneakpeek/'
        );
    }

    public static function mediainfoPayload(File $file): PayloadDTO
    {
        return new self(
            remote_file_id: $file->id,
            source: temporary_url($file->originalDownloadUrl),
            webhook_url: config('nataero.mediainfo_webhook_url'),
            operation: 'mediainfo',
            outputs: [],
            service_url: config('nataero.query_base_url') . '/services/mediainfo/'
        );
    }

    public static function mediaExifPayload(File $file): PayloadDTO
    {
        return new self(
            remote_file_id: $file->id,
            source: temporary_url($file->originalDownloadUrl),
            webhook_url: config('nataero.exif_webhook_url'),
            operation: 'mediaexif',
            outputs: [],
            service_url: config('nataero.query_base_url') . '/services/exif/'
        );
    }

    public static function hyper1Payload(File $file): PayloadDTO
    {
        return new self(
            remote_file_id: $file->id,
            source: temporary_url($file->originalDownloadUrl),
            webhook_url: config('nataero.hyper1_webhook_url'),
            operation: 'hyper1',
            outputs: [],
            service_url: config('nataero.query_base_url') . '/services/hyper1/',
            details: 'hyper1',
            file_type: $file->type ?? 'any',
        );
    }

    public static function viewOutput(File $file, int $hoursUntilExpiry = 8): array
    {
        $outputExtension = match ($file->type) {
            AssetType::Audio->value => 'mp3',
            AssetType::Video->value => 'mp4',
            default                 => 'jpg',
        };

        $derivativeKey = Path::join(
            config('manager.directory.derivatives'),
            $file->id,
            "{$file->id}." . $outputExtension
        );

        $viewUrlPut = PresignedUrl::putUrl($derivativeKey, $hoursUntilExpiry);

        return [
            'name'        => 'view_url',
            'destination' => $viewUrlPut,
            'config'      => [
                'width'             => 1920,
                'height'            => 1080,
                'keep_aspect_ratio' => true,
                'format'            => 'jpg',
                'quality'           => 90,
                'background'        => '#FFFFFF',
                'gravity'           => 'center',
                'crop'              => false,
                'strip_metadata'    => true,
            ],
        ];
    }

    public static function thumbOutput(File $file, int $hoursUntilExpiry = 8): array
    {
        $thumbKey = Path::join(
            config('manager.directory.thumbnails'),
            $file->id,
            "{$file->id}_thumb.jpg"
        );
        $thumbPut = PresignedUrl::putUrl($thumbKey, $hoursUntilExpiry);

        return [
            'name'        => 'thumbnail',
            'destination' => $thumbPut,
            'config'      => [
                'width'             => 512,
                'height'            => 512,
                'keep_aspect_ratio' => true,
                'format'            => 'jpg',
                'quality'           => 80,
                'background'        => '#FFFFFF',
                'gravity'           => 'center',
                'crop'              => false,
                'strip_metadata'    => true,
            ],
        ];
    }

    private static function pdfPayload(File $file, int $hoursUntilExpiry, array $viewOutput, array $thumbOutput): array
    {
        $pagesPrefix = Path::join(
            config('manager.directory.derivatives'),
            $file->id
        );

        $pagesPost = PresignedUrl::postPolicy(
            keyPrefix: $pagesPrefix,
            ttlHours: $hoursUntilExpiry,
            conditions: [
                ['starts-with', '$Content-Type', 'image/'],
            ],
            maxBytes: 50 * 1024 * 1024
        );
        $pdfPages = [
            'name'        => 'pdf',
            'destination' => null,
            'config'      => [
                'format'  => 'jpg',
                'quality' => 90,
                'dpi'     => 200,
            ],
            'pages' => [
                'upload' => $pagesPost,
                'naming' => "converted-{$file->slug}-{page}.jpg",
            ],
        ];

        $viewOutput['content_type'] = 'application/pdf';

        return [
            $viewOutput,
            $thumbOutput,
            $pdfPages,
        ];
    }

    private static function imagePayload(?string $conversionType, array $viewOutput, array $thumbOutput): array
    {
        return match ($conversionType) {
            null        => [$viewOutput, $thumbOutput],
            'view_url'  => [$viewOutput],
            'thumbnail' => [$thumbOutput],
            default     => throw new InvalidArgumentException("Unknown conversionType '{$conversionType}'"),
        };
    }
}
