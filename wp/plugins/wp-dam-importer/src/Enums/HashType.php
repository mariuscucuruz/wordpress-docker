<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum HashType: int
{
    case Unknown = 0;
    case MD5 = 1;
    case SHA1 = 2;
    case SHA256 = 3;
}
