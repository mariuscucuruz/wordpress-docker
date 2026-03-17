<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\DTOs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use MariusCucuruz\DAMImporter\Enums\OAuthTokenType;

class OAuthToken
{
    public function __construct(
        public string $accessToken,
        public CarbonImmutable $accessExpiresAt,
        public ?string $refreshToken,
        public ?CarbonImmutable $refreshExpiresAt,
        public ?string $scopes,
        public OAuthTokenType $type,
        public ?CarbonImmutable $createdAt,
    ) {
        $this->createdAt = $this->createdAt ?? CarbonImmutable::now();
    }

    public static function make(
        string $accessToken,
        Carbon|CarbonImmutable|int $accessExpiresAt,
        ?string $refreshToken,
        Carbon|CarbonImmutable|int|null $refreshExpiresAt,
        ?string $scopes,
        OAuthTokenType $type = OAuthTokenType::Unknown,
    ): static {
        if (is_int($accessExpiresAt)) {
            $accessExpiresAt = Carbon::createFromTimestamp($accessExpiresAt)->toImmutable();
        }

        if (is_int($refreshExpiresAt)) {
            $refreshExpiresAt = Carbon::createFromTimestamp($refreshExpiresAt)->toImmutable();
        }

        if ($accessExpiresAt instanceof Carbon) {
            $accessExpiresAt = $accessExpiresAt->toImmutable();
        }

        if ($refreshExpiresAt instanceof Carbon) {
            $refreshExpiresAt = $refreshExpiresAt->toImmutable();
        }

        return new self(
            $accessToken,
            $accessExpiresAt,
            $refreshToken,
            $refreshExpiresAt,
            $scopes,
            $type,
            CarbonImmutable::now(),
        );
    }

    public function expiresSoon(): bool
    {
        return $this->accessExpiresAt->subMinutes(5)->isPast();
    }

    public function expired(): bool
    {
        return $this->accessExpiresAt->isPast();
    }

    public function valid(): bool
    {
        return ! $this->expired();
    }

    public function canRefresh(): bool
    {
        if (! $this->refreshToken || $this->isRefreshTokenExpired()) {
            return false;
        }

        return true;
    }

    public function isRefreshTokenExpired(): bool
    {
        if (! $this->refreshExpiresAt) {
            return false;
        }

        return $this->refreshExpiresAt->isPast();
    }

    public function toArray(): array
    {
        $tokens = [
            'access_token'             => $this->accessToken ?? null,
            'expires'                  => $this->accessExpiresAt ?? null,
            'access_token_expires_at'  => $this->accessExpiresAt ?? null,
            'refresh_token'            => $this->refreshToken ?? null,
            'refresh_token_expires_at' => $this->refreshExpiresAt ?? null,
            'response_type'            => $this->type?->value ?? null,
            'created'                  => $this->createdAt ?? null,
        ];

        return array_filter($tokens);
    }
}
