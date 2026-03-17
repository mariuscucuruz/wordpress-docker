<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum ImportGroupStatus: string
{
    case INITIATED = 'INITIATED';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
    case UNAUTHORIZED = 'UNAUTHORIZED';
    case NO_FILES_FOUND = 'NO_FILES_FOUND';
}
