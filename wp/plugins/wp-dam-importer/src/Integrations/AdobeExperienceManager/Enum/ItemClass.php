<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AdobeExperienceManager\Enum;

enum ItemClass: string
{
    case FOLDER = 'assets/folder';
    case ASSET = 'assets/asset';
}
