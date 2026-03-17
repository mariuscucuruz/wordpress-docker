<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\DTOs;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use InvalidArgumentException;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Carbon as LaravelCarbon;

class DateRange implements Jsonable
{
    public function __construct(
        public Carbon|LaravelCarbon|null $start,
        public Carbon|LaravelCarbon|null $end,
    ) {
        if ($this->end === null) {
            $this->end = $this->start;
        }

        $this->start = $this->start->startOfDay();
        $this->end = $this->end->endOfDay();

        if ($this->start->gt($this->end)) {
            throw new InvalidArgumentException('The start date cannot be greater than the end date.');
        }
    }

    public static function make(Carbon|LaravelCarbon $start, Carbon|LaravelCarbon|null $end = null): self
    {
        return new self($start, $end);
    }

    public function isSingle(): bool
    {
        return $this->start->startOfDay() === $this->end->startOfDay();
    }

    public function isRange(): bool
    {
        return ! $this->isSingle();
    }

    public function contains(Carbon|LaravelCarbon $date): bool
    {
        return ($this->start->isBefore($date) || $this->start->equalTo($date))
            && ($this->end->isAfter($date) || $this->end->equalTo($date));
    }

    public function within(self $range): bool
    {
        return $this->start->gte($range->start) && $this->end->lte($range->end);
    }

    public function duration(): CarbonInterval
    {
        return CarbonInterval::make($this->start->diff($this->end, true));
    }

    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601ZuluString(),
            'end'   => $this->end->toIso8601ZuluString(),
        ];
    }

    public function toJson($options = 0): ?string
    {
        return json_encode($this->toArray(), $options);
    }
}
