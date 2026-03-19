<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums;

use Illuminate\Support\Collection;

enum RekognitionJobStatus: string
{
    case FAILED = 'FAILED';
    case SUCCEEDED = 'SUCCEEDED';
    case PENDING = 'PENDING';
    case IN_PROGRESS = 'IN_PROGRESS';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
    case COMPLETED = 'COMPLETED';
    case TIMED_OUT = 'TIMED_OUT';

    public static function all(): Collection
    {
        return collect(self::values());
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
