<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Metaads\Enums;

enum MetaAdsAdType: string
{
    case IMAGE = 'image';
    case VIDEO = 'video';
    case CAROUSEL = 'carousel';

    case DYNAMIC_VIDEO = 'dynamic_video';
    case DYNAMIC_IMAGE = 'dynamic_image';
}
