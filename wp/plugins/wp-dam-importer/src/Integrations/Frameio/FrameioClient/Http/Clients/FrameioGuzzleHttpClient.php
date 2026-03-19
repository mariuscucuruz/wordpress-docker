<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Http\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class FrameioGuzzleHttpClient implements FrameioHttpClientInterface
{
    public function send($url, $method, $body, $headers = [])
    {
        try {
            return (new Client(compact('headers')))
                ->request($method, $url, $body);
        } catch (ClientException $e) {
            if ($e->hasResponse()) {
                return $e->getResponse();
            }
        }
    }
}
