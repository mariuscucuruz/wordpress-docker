<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs;

use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class ActorRunInputs extends BaseDTO
{
    public string $serviceId;

    public string $startUrl;

    public int $maxDepth = 4;

    public int $maxRedirects = 25;

    public int $requestsPerCrawl = 200;

    public int $timeout = 1800;

    public string $email;

    public string $identifier;

    public string $webhookUrl;

    public bool $includeInlineCssImages = true;

    public bool $includeStylesheetImages = true;

    public static function make(
        string $serviceId,
        string $startUrl,
        string $email,
        ?int $timeout = 1800,
        ?int $maxDepth = 4,
        ?int $maxRedirects = 25,
        ?int $requestsPerCrawl = 200,
        ?bool $includeInlineCssImages = true,
        ?bool $includeStylesheetImages = true,
    ): static {
        // all keys must be declared as public properties on the DTO
        return self::fromArray([
            'serviceId'               => $serviceId,
            'startUrl'                => $startUrl,
            'email'                   => $email,
            'maxDepth'                => $maxDepth ?? (int) config('websweep.max_depth', 4),
            'maxRedirects'            => $maxRedirects ?? (int) config('websweep.max_redirects', 25),
            'requestsPerCrawl'        => $requestsPerCrawl ?? (int) config('websweep.requests_per_crawl', 200),
            'includeInlineCssImages'  => $includeInlineCssImages ?? true,
            'includeStylesheetImages' => $includeStylesheetImages ?? true,
            'timeout'                 => $timeout ?? (int) config('websweep.timeout', 1800),
            'webhookUrl'              => config('websweep.webhook_uri') ?? url(path: '/webhooks/websweep', secure: true),
            'identifier'              => sha1(config('app.url') . "{$startUrl}{$email}"),
        ]);
    }
}
