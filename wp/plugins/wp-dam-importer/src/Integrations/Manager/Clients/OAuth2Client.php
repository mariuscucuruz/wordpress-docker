<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Clients;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\OAuthToken;
use Illuminate\Http\Client\PendingRequest;
use MariusCucuruz\DAMImporter\Enums\OAuthTokenType;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use MariusCucuruz\DAMImporter\Exceptions\OAuthTokenRefreshFailed;
use MariusCucuruz\DAMImporter\Exceptions\OAuthTokenRetrievalFailed;

abstract class OAuth2Client
{
    public string $clientId;

    public string $clientSecret;

    public string $redirectUri;

    public string $tokenUrl;

    public string $authorizeUrl;

    public string $scope;

    protected ?OAuthToken $currentToken = null;

    public function __construct()
    {
        $this->initialize();
        $this->validateSettings();
    }

    protected function validateSettings(): void
    {
        $authorizeUrl = filter_var($this->authorizeUrl, FILTER_VALIDATE_URL, FILTER_NULL_ON_FAILURE);
        $tokenUrl = filter_var($this->tokenUrl, FILTER_VALIDATE_URL, FILTER_NULL_ON_FAILURE);
        $redirectUri = filter_var($this->redirectUri, FILTER_VALIDATE_URL, FILTER_NULL_ON_FAILURE);
        $clientId = filter_var($this->clientId, FILTER_FLAG_EMPTY_STRING_NULL, FILTER_NULL_ON_FAILURE);
        $clientSecret = filter_var($this->clientSecret, FILTER_FLAG_EMPTY_STRING_NULL, FILTER_NULL_ON_FAILURE);

        if (empty($authorizeUrl)) {
            throw InvalidSettingValue::make('authorizeUrl');
        }

        if (empty($tokenUrl)) {
            throw InvalidSettingValue::make('tokenUrl');
        }

        if (empty($redirectUri)) {
            throw InvalidSettingValue::make('redirectUri');
        }

        if (empty($clientId)) {
            throw InvalidSettingValue::make('clientId');
        }

        if (empty($clientSecret)) {
            throw InvalidSettingValue::make('clientSecret');
        }
    }

    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * This method should initialize the clientId, secret, redirectUri, tokenUrl, authorizeUrl and scope
     * from the configuration files, database, or any other source.
     */
    abstract protected function initialize(): void;

    /**
     * This method should return the state to be passed to the authorization URL.
     */
    abstract public function getState(?Collection $settings): string;

    /**
     * This method should return a new Http client with the necessary configuration to make a token request for
     * retrieving a new access token or refreshing an existing one, such as configuring the authorization.
     * It should extend the http() method when possible.
     */
    abstract public function tokenHttp(): PendingRequest;

    public function getAuthorizationUrl(?Collection $settings)
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'scope'         => $this->scope,
            'state'         => $this->getState($settings),
        ]);

        return "{$this->authorizeUrl}?{$query}";
    }

    protected function http(): PendingRequest
    {
        return Http::maxRedirects(10)
            ->timeout(60)
            ->connectTimeout(60)
            ->withUserAgent(config('manager.oauth2_client.user_agent'))
            ->acceptJson();
    }

    /**
     * @throws \MariusCucuruz\DAMImporter\Exceptions\OAuthTokenRetrievalFailed
     */
    public function fetchToken(?string $code, mixed $state = null): OAuthToken
    {
        if ($this->token() && $this->token()->valid()) {
            return $this->token();
        }

        $code = $code ?? request()?->get('code') ?? '';
        $state = $state ?? request()?->get('state') ?? [];
        $scope = request()?->get('scope') ?? $this->scope;

        $response = $this->tokenHttp()->post($this->tokenUrl, [
            'grant_type'   => 'authorization_code',
            'scope'        => $scope,
            'state'        => json_decode($state),
            'redirect_uri' => $this->redirectUri,
            'code'         => $code,
        ]);

        if (! $response->successful()) {
            throw OAuthTokenRetrievalFailed::make();
        }

        $data = $response->json();

        $this->currentToken = OAuthToken::make(
            $data['access_token'],
            now()->addSeconds((int) $data['expires_in']), // expires_in is in seconds, according to the RFC
            $data['refresh_token'] ?? null, // optional, according to RFC
            null,
            $data['scope'] ?? null, // optional, according to RFC
            OAuthTokenType::tryFrom($data['token_type']) ?? OAuthTokenType::Bearer,
        );

        return $this->token();
    }

    public function refreshToken(?OAuthToken $token): OAuthToken
    {
        if (! $token) {
            $token = $this->token();
        }

        if (empty($token->accessToken)) {
            throw OAuthTokenRefreshFailed::make();
        }

        if (! $token->canRefresh()) {
            return $token;
        }

        $response = $this->tokenHttp()->post($this->tokenUrl, [
            'grant_type'    => 'refresh_token',
            'scope'         => $token->scopes,
            'refresh_token' => $token->refreshToken,
        ]);

        if (! $response->successful()) {
            throw OAuthTokenRefreshFailed::make();
        }

        $data = $response->json();

        $this->currentToken = OAuthToken::make(
            $data['access_token'],
            now()->addSeconds((int) $data['expires_in']),
            $data['refresh_token'] ?? null,
            null,
            $data['scope'] ?? null,
            OAuthTokenType::tryFrom($data['token_type']) ?? OAuthTokenType::Bearer,
        );

        return $this->currentToken;
    }

    public function token(): ?OAuthToken
    {
        return $this->currentToken;
    }
}
