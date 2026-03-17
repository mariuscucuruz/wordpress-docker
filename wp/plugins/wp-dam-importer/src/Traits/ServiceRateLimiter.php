<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use Illuminate\Support\Facades\RateLimiter;

trait ServiceRateLimiter
{
    protected int $defaultCooldownSeconds = 3600;

    public function cacheKey(): string
    {
        return $this->service?->name ?? strtolower(class_basename(static::class)) . '.rate_limit';
    }

    public function incrementAttempts($decaySeconds = 3600, $amount = 1): void
    {
        RateLimiter::increment($this->cacheKey(), $decaySeconds, $amount);
    }

    public function clearRateLimiter(): void
    {
        RateLimiter::clear($this->cacheKey());
    }

    public function delay(?int $cooldownPeriodSeconds = null): int
    {
        $maxAttempts = config($this->cacheKey());
        $attempts = RateLimiter::attempts($this->cacheKey());

        if ($maxAttempts && $attempts >= $maxAttempts) {
            return intdiv((int) $attempts, $maxAttempts) * $this->secondsToCooldown($cooldownPeriodSeconds);
        }

        return 0;
    }

    public function secondsToCooldown(?int $cooldownPeriodSeconds = null)
    {
        if ($cooldownPeriodSeconds && $cooldownPeriodSeconds > 0) {
            $this->defaultCooldownSeconds = $cooldownPeriodSeconds;
        }

        return $this->defaultCooldownSeconds;
    }
}
