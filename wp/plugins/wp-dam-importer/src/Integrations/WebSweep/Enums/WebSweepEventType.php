<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Enums;

use Illuminate\Support\Collection;

enum WebSweepEventType: string
{
    // crawler callback:
    case STATUS_UPDATE = 'status-update';

    // lifecycle events:
    case READY = 'ACTOR.RUN.READY';
    case ABORTED = 'ACTOR.RUN.ABORTED';
    case ABORTING = 'ACTOR.RUN.ABORTING';
    case FAILED = 'ACTOR.RUN.FAILED';
    case RUNNING = 'ACTOR.RUN.RUNNING';
    case SUCCEED = 'ACTOR.RUN.SUCCEED';
    case SUCCEEDED = 'ACTOR.RUN.SUCCEEDED';
    case TIMED_OUT = 'ACTOR.RUN.TIMED_OUT';
    case TIMING_OUT = 'ACTOR.RUN.TIMING_OUT';

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
            self::SUCCEED,
            self::SUCCEEDED,
            self::FAILED,
            self::ABORTED,
            self::TIMED_OUT,
        ]);
    }
}
