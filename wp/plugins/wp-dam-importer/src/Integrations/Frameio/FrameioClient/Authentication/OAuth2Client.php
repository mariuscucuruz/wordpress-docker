<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Authentication;

use MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\FrameioApp;
use MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\FrameioClient;

class OAuth2Client
{
    protected $app;

    protected $client;

    public function __construct(FrameioApp $app, FrameioClient $client)
    {
        $this->app = $app;
        $this->client = $client;
    }

    public function getAuthorizationUrl(?string $redirectUri = null, ?array $params = null, ?string $scope = null)
    {
        $params ??= [];
        $authUrl = config('frameio.authorize_url');

        $credentials = [
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'client_id'     => $this->getApp()->getClientId(),
            'scope'         => $scope,
            'state'         => $params['state'] ?? null,
        ];

        return $authUrl . '?' . http_build_query($credentials);
    }

    public function getApp()
    {
        return $this->app;
    }

    public function getAccessToken($code, $scope, $grant_type, $state = null, $redirectUri = null)
    {
        $params = [];

        if ($grant_type == 'authorization_code') {
            $params = [
                'grant_type'   => $grant_type,
                'code'         => $code,
                'redirect_uri' => $redirectUri,
                'state'        => $state,
                'scope'        => $scope,
            ];
        }

        if ($grant_type == 'refresh_token') {
            $params = [
                'grant_type'    => $grant_type,
                'scope'         => $scope,
                'refresh_token' => $code,
            ];
        }

        $payload = [
            'auth'        => [$this->getApp()->getClientId(), $this->getApp()->getClientSecret()],
            'form_params' => $params,
        ];

        $tokenUrl = config('frameio.token_url');
        $response = $this->getClient()->getHttpClient()->send($tokenUrl, 'POST', $payload);
        $contents = $response->getBody()->getContents();

        return json_decode((string) $contents, true);
    }

    public function getClient()
    {
        return $this->client;
    }
}
