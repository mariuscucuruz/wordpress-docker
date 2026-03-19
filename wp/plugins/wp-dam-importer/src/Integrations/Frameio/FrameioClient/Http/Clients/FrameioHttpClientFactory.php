<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Http\Clients;

use InvalidArgumentException;
use GuzzleHttp\Client as Guzzle;

class FrameioHttpClientFactory
{
    public static function make($handler)
    {
        if (! $handler) {
            return new FrameioGuzzleHttpClient;
        }

        if ($handler instanceof FrameioHttpClientInterface) {
            return $handler;
        }

        if ($handler instanceof Guzzle) {
            return new FrameioGuzzleHttpClient;
        }

        throw new InvalidArgumentException(
            'The http client handler must be an instance of GuzzleHttp\Client or an instance of FrameioHttpClientInterface.'
        );
    }
}
