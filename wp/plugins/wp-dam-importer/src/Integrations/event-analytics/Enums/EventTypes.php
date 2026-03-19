<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\EventAnalytics\Enums;

enum EventTypes: string
{
    case Click = 'Click';
    case Download = 'Download';
    case PageView = 'PageView';
    case Unknown = 'Unknown';
    /** @deprecated  */
    case click = 'click';

    public static function all()
    {
        return array_column(self::cases(), 'value');
    }
}
