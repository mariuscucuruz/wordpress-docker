<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum OauthAppStatus: string
{
    case TESTING = 'TESTING';
    case PUBLISHED = 'PUBLISHED';
}
