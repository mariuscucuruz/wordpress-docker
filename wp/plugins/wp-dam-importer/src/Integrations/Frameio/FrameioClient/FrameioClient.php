<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient;

use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Http\Clients\FrameioHttpClientInterface;

class FrameioClient
{
    protected $httpClient;

    public function __construct(FrameioHttpClientInterface $httpClient)
    {
        $this->setHttpClient($httpClient);
    }

    public function getHttpClient()
    {
        return $this->httpClient;
    }

    public function setHttpClient(FrameioHttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;

        return $this;
    }

    public function sendRequest(FrameioRequest $request)
    {
        $method = $request->getMethod();

        [$url, $headers, $requestBody] = $this->prepareRequest($request);

        return $this->getHttpClient()->send($url, $method, $requestBody, $headers);
    }

    protected function buildAuthHeader($accessToken = '')
    {
        return ['Authorization' => 'Bearer ' . $accessToken];
    }

    protected function buildContentTypeHeader($contentType = '')
    {
        return ['Content-Type' => $contentType];
    }

    protected function buildUrl($endpoint = '')
    {
        $base = config('frameio.baseUrl');

        if (str_starts_with($endpoint, 'http')) {
            return $endpoint;
        }

        return Path::join($base, $endpoint);
    }

    protected function prepareRequest(FrameioRequest $request)
    {
        $url = $this->buildUrl($request->getEndpoint());

        $requestBody = $request->getParams();

        $headers = [
            ...$this->buildAuthHeader($request->getAccessToken()),
            ...$this->buildContentTypeHeader($request->getContentType()),
            ...$request->getHeaders(),
        ];

        return [$url, $headers, $requestBody];
    }
}
