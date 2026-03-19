<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Interfaces;

interface HasDateRangeFilter
{
    public function isWithinDatePeriod(mixed $timestamp): bool;
}
