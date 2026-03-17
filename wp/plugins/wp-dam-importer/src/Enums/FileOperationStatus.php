<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum FileOperationStatus: string
{
    // NOTE: `PENDING` is only used at database level do not use it in the codebase.
    case PENDING = 'pending';
    // NOTE: `INITIALIZED` is only used before a job is dispatched, usually in console commands.
    case INITIALIZED = 'initialized';
    // NOTE: `PROCESSING` is used after a job is dispatched, inside the job itself..
    case PROCESSING = 'processing';
    case SUCCESS = 'success';
    case FAILED = 'failed';
}
