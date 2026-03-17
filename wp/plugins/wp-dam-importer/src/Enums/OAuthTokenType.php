<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum OAuthTokenType: string
{
    case Unknown = 'unknown';
    case Bearer = 'bearer';
}
