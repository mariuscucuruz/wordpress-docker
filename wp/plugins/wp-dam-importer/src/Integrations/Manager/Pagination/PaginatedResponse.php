<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Pagination;

readonly class PaginatedResponse
{
    public function __construct(
        public array $items,
        public array $subfolders = [],
        public mixed $nextCursor = null,
    ) {}
}
