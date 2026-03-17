<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

trait HandlesVulcanHttpRequests
{
    protected function http(string $token, string $serverKey = ''): PendingRequest
    {
        return Http::withHeaders([
            'X-Api-Key'    => $token,
            'X-Server-Key' => $serverKey,
            'User-Agent'   => 'medialake-platform/1.x',
        ])
            ->asJson()
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(config('queue.timeout'))
            ->retry(3, 200);
    }
}
