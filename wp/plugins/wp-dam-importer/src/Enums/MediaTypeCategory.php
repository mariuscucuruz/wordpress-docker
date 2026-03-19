<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum MediaTypeCategory: string
{
    case Broadcast = 'Broadcast';
    case Digital = 'Digital';
    case DirectMarketing = 'Direct Marketing';
    case OOH = 'OOH';
    case Print = 'Print';
    case Retail = 'Retail';
    case SocialMedia = 'Social Media';
}
