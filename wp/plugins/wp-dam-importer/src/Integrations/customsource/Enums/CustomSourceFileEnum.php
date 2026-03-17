<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\CustomSource\Enums;

enum CustomSourceFileEnum: string
{
    case PENDING = 'PENDING';
    case COMPLETE = 'COMPLETE';
}
