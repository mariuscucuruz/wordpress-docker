<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Enums;

enum NataeroConversionTypes: string
{
    case VIEW_URL = 'view_url';
    case THUMBNAIL = 'thumbnail';
}
