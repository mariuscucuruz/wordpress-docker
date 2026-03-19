<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\GoogleAds\Enum;

enum GoogleAdAdType: string
{
    case RESPONSIVE_DISPLAY_AD = 'RESPONSIVE_DISPLAY_AD';
    case VIDEO_RESPONSIVE_AD = 'VIDEO_RESPONSIVE_AD';
    case IMAGE_AD = 'IMAGE_AD';

    public static function supportedTypes(): array
    {
        return [
            self::RESPONSIVE_DISPLAY_AD->value,
            self::VIDEO_RESPONSIVE_AD->value,
            self::IMAGE_AD->value,
        ];
    }
}
