<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum FileRemoteStatus: string
{
    case AVAILABLE = 'available';
    case UNAVAILABLE = 'unavailable';
}
