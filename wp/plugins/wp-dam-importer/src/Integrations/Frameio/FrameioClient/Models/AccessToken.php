<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Models;

class AccessToken extends BaseModel
{
    protected $token;

    protected $refreshToken;

    protected $expiryTime;

    protected $tokenType;

    protected $scope;

    public function __construct(array $data)
    {
        parent::__construct($data);

        $this->token = $this->getDataProperty('access_token');
        $this->tokenType = $this->getDataProperty('token_type');
        $this->scope = $this->getDataProperty('scope');
        $this->expiryTime = $this->getDataProperty('expires_in');
        $this->refreshToken = $this->getDataProperty('refresh_token');
    }

    public function getToken()
    {
        return $this->token;
    }

    public function getRefreshToken()
    {
        return $this->refreshToken;
    }

    public function getExpiryTime()
    {
        return (int) $this->expiryTime;
    }

    public function getTokenType()
    {
        return $this->tokenType;
    }

    public function getScope()
    {
        return $this->scope;
    }
}
