<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient;

class FrameioRequest
{
    protected $accessToken;

    protected $method = 'GET';

    protected $params;

    protected $endpoint;

    protected $headers = [];

    protected $contentType = 'application/json';

    public function __construct($method, $endpoint, $accessToken, ?array $params = null, ?array $headers = null, $contentType = null)
    {
        $headers ??= [];
        $params ??= [];
        $this->setMethod($method);
        $this->setEndpoint($endpoint);
        $this->setAccessToken($accessToken);
        $this->setParams($params);
        $this->setHeaders($headers);

        if ($contentType) {
            $this->setContentType($contentType);
        }
    }

    public function getMethod()
    {
        return $this->method;
    }

    public function setMethod($method)
    {
        $this->method = $method;

        return $this;
    }

    public function getAccessToken()
    {
        return $this->accessToken;
    }

    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    public function getEndpoint()
    {
        return $this->endpoint;
    }

    public function setEndpoint($endpoint)
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function getContentType()
    {
        return $this->contentType;
    }

    public function setContentType($contentType)
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function setHeaders($headers)
    {
        $this->headers = [...$this->headers, ...$headers];

        return $this;
    }

    public function getParams()
    {
        return $this->params;
    }

    public function setParams($params)
    {
        $this->params = $params;

        return $this;
    }
}
