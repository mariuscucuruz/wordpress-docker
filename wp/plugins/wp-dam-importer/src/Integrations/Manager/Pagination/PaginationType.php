<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Pagination;

enum PaginationType: string
{
    case Token = 'token';
    case Cursor = 'cursor';
    case Page = 'page';
    case None = 'none';
}
