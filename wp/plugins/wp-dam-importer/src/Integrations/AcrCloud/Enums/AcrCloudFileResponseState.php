<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Enums;

enum AcrCloudFileResponseState: string
{
    case RESULTS = 'RESULTS';
    case NO_RESULTS = 'NO_RESULTS';
    case ERROR = 'ERROR';

    public static function makeFromInt(null|int|string $state): ?AcrCloudFileResponseState
    {
        return match ((int) $state) {
            1       => self::RESULTS,
            -1      => self::NO_RESULTS,
            -2      => self::ERROR,
            default => null
        };
    }
}
