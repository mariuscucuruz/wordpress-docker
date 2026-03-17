<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Enums;

use InvalidArgumentException;

enum NataeroTaskStatus: string
{
    case INITIATED = 'INITIATED';
    case PROCESSING = 'PROCESSING';
    case FAILED = 'FAILED';
    case SUCCEEDED = 'SUCCEEDED';
    case CHECKING_RESULTS = 'CHECKING_RESULTS';

    public static function resolveFromString(string $status): self
    {
        return match (strtoupper($status)) {
            'INITIATED'        => self::INITIATED,
            'PROCESSING'       => self::PROCESSING,
            'FAILED'           => self::FAILED,
            'SUCCESS'          => self::SUCCEEDED,
            'CHECKING_RESULTS' => self::CHECKING_RESULTS,
            default            => throw new InvalidArgumentException("Invalid Nataero task status: {$status}"),
        };
    }
}
