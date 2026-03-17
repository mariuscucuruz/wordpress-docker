<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Authentication;

use MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Models\AccessToken;

class FrameioAuthHelper
{
    protected $oAuth2Client;

    public function __construct(OAuth2Client $oAuth2Client)
    {
        $this->oAuth2Client = $oAuth2Client;
    }

    public function getOAuth2Client()
    {
        return $this->oAuth2Client;
    }

    public function getAuthUrl(?string $redirectUri = null, ?array $params = null, ?string $scope = null)
    {
        $params ??= [];

        return $this->getOAuth2Client()->getAuthorizationUrl($redirectUri, $params, $scope);
    }

    public function getAccessToken($code, $scope, $grantType = 'authorization_code', $state = null, $redirectUri = null)
    {
        $accessToken = $this->getOAuth2Client()->getAccessToken($code, $scope, $grantType, $state, $redirectUri);

        return new AccessToken($accessToken);
    }

    public function getRefreshedAccessToken($token, $scope, $grantType = 'refresh_token')
    {
        $accessToken = $this->getOAuth2Client()->getAccessToken($token, $scope, $grantType);

        return new AccessToken($accessToken);
    }
}
