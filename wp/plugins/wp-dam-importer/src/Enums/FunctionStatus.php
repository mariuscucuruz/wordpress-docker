<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum FunctionStatus: int
{
    // KEYS:
    case PROCESSING = 0;
    case SUCCEEDED = 1;
    case FAILED = 2;
    // AS VALUES:
    case JOB_SENT_TO_QUEUE = 3;
    case CHECKING_RESULTS = 4;
}
