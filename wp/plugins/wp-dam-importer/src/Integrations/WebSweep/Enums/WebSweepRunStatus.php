<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Enums;

use Illuminate\Support\Collection;

enum WebSweepRunStatus: string
{
    // crawler callback:
    case STOPPED = 'stopped';

    // lifecycle:
    case READY = 'READY';
    case ABORTED = 'ABORTED';
    case ABORTING = 'ABORTING';
    case FAILED = 'FAILED';
    case RUNNING = 'RUNNING';
    case SUCCEEDED = 'SUCCEEDED';
    case TIMED_OUT = 'TIMED-OUT';
    case TIMING_OUT = 'TIMING-OUT';

    public static function runningCases(): Collection
    {
        return collect([
            self::READY,
            self::RUNNING,
            self::TIMING_OUT,
            self::ABORTING,
        ]);
    }

    public static function stoppedCases(): Collection
    {
        return collect([
            self::SUCCEEDED,
            self::FAILED,
            self::ABORTED,
            self::TIMED_OUT,
        ]);
    }
}
