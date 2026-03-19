<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use Exception;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * Provides date range filtering for sync operations.
 * Implements HasDateRangeFilter interface methods.
 */
trait FiltersDateRange
{
    public ?CarbonPeriod $syncFilterDateRange = null;

    public bool $isDateSyncFilter = false;

    /**
     * Check if a timestamp is within the configured date period filter.
     */
    public function isWithinDatePeriod(mixed $timeInput): bool
    {
        try {
            $timestamp = Carbon::parse($timeInput);
        } catch (Exception $e) {
            $this->log("Error creating date from timestamp: {$timeInput}. Error: {$e->getMessage()}.", 'error');

            return false;
        }

        return $timestamp->betweenIncluded($this->syncFilterDateRange?->start, $this->syncFilterDateRange?->end);
    }
}
