<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Enums;

enum MediaconvertJobStatus: string
{
    case SUBMITTED = 'SUBMITTED';
    case PROGRESSING = 'PROGRESSING';
    case COMPLETE = 'COMPLETE';
    case ERROR = 'ERROR';
}
