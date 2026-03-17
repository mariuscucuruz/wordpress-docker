<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds\Enums;

enum GrantType: string
{
    case AUTHORIZATION_CODE = 'authorization_code';
    case CLIENT_CREDENTIALS = 'client_credentials';
    case REFRESH_TOKEN = 'refresh_token';
}
