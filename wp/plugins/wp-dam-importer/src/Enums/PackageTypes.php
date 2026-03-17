<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum PackageTypes: string
{
    case SOURCE = 'source';
    case STORAGE = 'storage';
    case FUNCTION = 'function';
    case DESTINATION = 'destination';
}
