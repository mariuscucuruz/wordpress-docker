<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Interfaces;

interface HasRateLimit
{
    public function cacheKey(): string;

    public function incrementAttempts(): void;

    public function delay(): int;

    public function clearRateLimiter(): void;
}
