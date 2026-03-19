<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\DTOs;

final readonly class FilePropertyResultDTO
{
    public function __construct(
        public bool $success,
        public ?int $statusCode = null,
        public array $properties = [],
        public ?string $message = null,
    ) {}

    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }

    public function isFailed(): bool
    {
        return $this->success === false && $this->statusCode !== 404;
    }
}
