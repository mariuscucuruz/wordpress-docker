<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\DTOs;

/**
 * Stores pagination information returned in the headers of API requests.
 */
class ApiPagination
{
    public function __construct(
        public int $pageNumber,
        public int $perPage,
        public ?int $total,
        public ?int $totalPages,
    ) {
        //
    }

    public static function make(int $pageNumber, int $perPage, ?int $total, ?int $totalPages): static
    {
        return new self($pageNumber, $perPage, $total, $totalPages);
    }

    public function isLastPage(?int $lastCount = null): bool
    {
        return match (true) {
            $lastCount !== null => $lastCount < $this->perPage,
            default             => $this->pageNumber >= ($this->totalPages ?? 0),
        };
    }

    public function hasMorePages(): bool
    {
        return $this->pageNumber < ($this->totalPages ?? 0);
    }

    public function reset(): static
    {
        return self::make(-1, 0, 0, 1);
    }
}
