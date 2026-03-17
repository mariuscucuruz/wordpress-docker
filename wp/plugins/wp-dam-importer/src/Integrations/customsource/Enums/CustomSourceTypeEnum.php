<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\CustomSource\Enums;

enum CustomSourceTypeEnum: string
{
    case GENERIC = 'GENERIC';
    case MEDIALAKE_APP = 'MEDIALAKE_APP';
}
