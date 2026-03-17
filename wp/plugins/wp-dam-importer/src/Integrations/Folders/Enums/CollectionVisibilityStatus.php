<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders\Enums;

enum CollectionVisibilityStatus: string
{
    case SHARED = 'SHARED';
    case PUBLIC = 'PUBLIC';
    case PRIVATE = 'PRIVATE';
    case ARCHIVED = 'ARCHIVED';
}
