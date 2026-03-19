<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum PackageType: string
{
    case Source = 'source';
    case Storage = 'storage';
    case Function = 'function';
    case Destination = 'destination';
}
