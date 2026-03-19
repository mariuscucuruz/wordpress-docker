<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Instagram\Traits;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use GuzzleHttp\Client;
use MariusCucuruz\DAMImporter\Enums\MetaType;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Support\LazyCollection;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use MariusCucuruz\DAMImporter\Integrations\Instagram\Enums\InstagramServiceType;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;

trait InstagramBasicDisplayApi
{
    public function initializeBasicDisplay(): void
    {
        $this->client = new Client;

        $settings = $this->getSettings();

        if (! isset($settings['clientId'], $settings['clientSecret'])) {
            $this->log('Instagram settings are required', 'error');

            return;
        }

        $this->clientId = $settings['clientId'];
        $this->clientSecret = $settings['clientSecret'];
        $this->redirectUri = $settings['redirectUri'];

        $this->handleTokenExpiration();
    }

    public function testSettingsBasicDisplay(Collection $settings): bool
    {
        if ($settings->isEmpty()) {
            $clientId = $this->clientId ?? '';
            $clientSecret = $this->clientSecret ?? '';
            $settings = collect(compact('clientId', 'clientSecret'));
        } else {
            $clientId = $settings->firstWhere('name', 'INSTAGRAM_CLIENT_ID')?->payload ?? '';
            $clientSecret = $settings->firstWhere('name', 'INSTAGRAM_SECRET')?->payload ?? '';
        }
        abort_if($settings->isEmpty(), 400, 'Instagram settings are required');
        abort_if(count(config('instagram.settings.INSTAGRAM_BASIC_DISPLAY_SETTINGS')) !== $settings->count(), 406, 'All Settings must be present');

        // Updated pattern to match client IDs with 10 to 15 digits at the start
        $clientIdPattern = '/^[a-zA-Z0-9]{15,}$/'; // max: 32?
        $clientSecretPattern = '/^[a-zA-Z0-9]{32,}$/'; // max: 64?

        abort_if(! preg_match($clientIdPattern, $clientId), 406, 'Looks like your client ID format is invalid');
        abort_if(
            ! preg_match($clientSecretPattern, $clientSecret),
            406,
            'Looks like your client secret format is invalid'
        );

        return true;
    }

    public function getSettingsBasicDisplay(): array
    {
        $settings = parent::getSettings(array_keys(config('instagram.settings.INSTAGRAM_BASIC_DISPLAY_SETTINGS', [])));

        $clientId = $settings['INSTAGRAM_CLIENT_ID'] ?? config('instagramBasicDisplay.client_id');
        $clientSecret = $settings['INSTAGRAM_SECRET'] ?? config('instagramBasicDisplay.client_secret');

        $redirectUri = config('instagramBasicDisplay.redirect_uri');

        return compact('clientId', 'clientSecret', 'redirectUri');
    }

    /**
     * @throws CouldNotGetToken
     */
    public function getTokensBasicDisplay(array $tokens = []): TokenDTO
    {
        try {
            $response = Http::timeout(config('queue.timeout'))->asForm()->post(config('instagramBasicDisplay.oauth_base_url') . '/oauth/access_token', [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri'  => $this->redirectUri,
                'grant_type'    => 'authorization_code',
                'code'          => request('code'),
            ]);

            throw_unless(
                $response->getStatusCode() == 200,
                CouldNotGetToken::class,
                'Failed to get access token.'
            );

            $body = json_decode($response->getBody()->getContents(), true);

            return new TokenDTO($this->storeTokenBasicDisplay($body));
        } catch (CouldNotGetToken|Throwable|Exception $e) {
            if ($e->getCode() === 401) {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    public function storeTokenBasicDisplay($body, bool $refresh = false): array
    {
        $this->accessToken = $body['access_token'];
        isset($body['expires_in']) ? $expires = now()->addseconds($body['expires_in'])->getTimestamp()
            : $expires = null;

        if (! $refresh) {
            // Exchange short live access token for long live access token
            $longLiveToken = $this->exchangeAccessTokenForLongLiveAccessToken($this->accessToken);

            if ($longLiveToken) {
                $this->accessToken = $longLiveToken[0];
                $expires = $longLiveToken[1];
            }
        }

        return [
            'access_token'  => $this->accessToken,
            'token_type'    => null,
            'expires'       => $expires,
            'token'         => 'bearer',
            'refresh_token' => null,
            'account_type'  => InstagramServiceType::PERSONAL->value,
        ];
    }

    public function exchangeAccessTokenForLongLiveAccessTokenBasicDisplay($token): ?array
    {
        try {
            $response = Http::timeout(config('queue.timeout'))->get(config('instagramBasicDisplay.query_base_url') . '/access_token', [
                'client_secret' => $this->clientSecret,
                'grant_type'    => 'ig_exchange_token',
                'access_token'  => $token,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            isset($body['expires_in']) ? $expires = now()->addseconds($body['expires_in'])->getTimestamp()
                : $expires = null;

            return [$body['access_token'], $expires];
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }
            $this->log($e->getMessage(), 'error');

            return null;
        }
    }

    public function getUserBasicDisplay(): ?UserDTO
    {
        try {
            $response = $this->client->get(config('instagramBasicDisplay.query_base_url') . '/me', [
                'query' => [
                    'fields'       => config('instagramBasicDisplay.fields.user'),
                    'access_token' => $this->accessToken,
                ],
            ]);
            $body = json_decode($response->getBody()->getContents(), true);

            $userName = data_get($body, 'username');
            $photo = $this->getProfilePhotoBasicDisplay($userName);

            return new UserDTO([
                'email' => $userName,
                'photo' => $photo,
                'name'  => $userName,
            ]);
        } catch (GuzzleException|Exception $e) {
            $this->log($e->getMessage(), 'error');

            return new UserDTO;
        }
    }

    public function getProfilePhotoBasicDisplay($username): ?string
    {
        try {
            $response = $this->client->get('https://www.instagram.com/' . $username . '/?__a=1&__d=1');
            $data = json_decode($response->getBody()->getContents(), true);

            $url = data_get($data, 'graphql.user.profile_pic_url');
            $thumbnailPath = null;

            if ($url) {
                $thumbnailPath = config('manager.directory.thumbnails')
                    . DIRECTORY_SEPARATOR
                    . $username
                    . DIRECTORY_SEPARATOR
                    . str()->random(6)
                    . '.jpg';

                $this->storage->put($thumbnailPath, file_get_contents($url));
            }

            return $thumbnailPath;
        } catch (GuzzleException|Exception $e) {
            $this->log('Unable to retrieve Instagram profile photo. Error: ' . $e->getMessage(), 'error');

            return null;
        }
    }

    /**
     * @deprecated
     */
    public function indexBasicDisplay(array $params = []): iterable
    {
        $this->handleTokenExpiration();

        try {
            $response = $this->client->get(config('instagramBasicDisplay.query_base_url') . '/me/media', [
                'query' => [
                    'fields'       => config('instagramBasicDisplay.fields.media'),
                    'access_token' => $this->service->access_token,
                    'limit'        => 500,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException|Exception $e) {
            if ($e->getCode() === 401) {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }
            $this->log($e->getMessage(), 'error');

            return [];
        }

        if (! isset($data['data'])) {
            return [];
        }

        return LazyCollection::make(function () use ($data) {
            yield from $data['data'];

            while ($nextUrl = data_get($data, 'paging.next')) {
                try {
                    $response = $this->client->get($nextUrl);
                    $data = json_decode($response->getBody()->getContents(), true);
                    yield from $data['data'];
                } catch (Exception $e) {
                    $this->log($e->getMessage(), 'error');

                    return [];
                }

                if (! data_get($data, 'data')) {
                    return [];
                }
            }
            // missing return statement
        });
    }

    public function checksForRefreshTokenExpiry($expires): bool
    {
        if (! $expires) {
            return true;
        }

        $currentTime = time();
        $now = date('Y-m-d H:i:s', $currentTime);
        $expires = date('Y-m-d H:i:s', (int) $expires);

        return $expires <= $now;
    }

    public function redirectToAuthUrlBasicDisplay(?Collection $settings = null, string $email = ''): void
    {
        try {
            $url = config('instagramBasicDisplay.oauth_base_url') . '/oauth/authorize';

            throw_unless(
                $url && $this->clientId && $this->redirectUri,
                CouldNotInitializePackage::class,
                'Instagram Basic Display settings are required!'
            );

            $state = $this->generateRedirectOauthState([
                'meta' => ['account_type' => InstagramServiceType::PERSONAL->value],
            ]);

            $queryParams = [
                'client_id'            => $this->clientId,
                'redirect_uri'         => $this->redirectUri,
                'response_type'        => 'code',
                'scope'                => config('instagramBasicDisplay.scope'),
                'force_authentication' => 1,
                'state'                => $state,
            ];

            $queryString = http_build_query($queryParams);
            $requestUrl = "{$url}?{$queryString}";
            $this->redirectTo($requestUrl);
        } catch (CouldNotInitializePackage|Throwable|Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function getNewDownloadUrlBasicDisplay(File $file): ?string
    {
        try {
            $response = Http::timeout(config('queue.timeout'))->get(config('instagramBasicDisplay.query_base_url') . '/' . $file->remote_service_file_id, [
                'access_token' => $this->service->access_token,
                'fields'       => 'media_url',
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if ($mediaUrl = data_get($data, 'media_url')) {
                $file->metas()->where('key', MetaType::extra->value)->update(['value->source_link' => $mediaUrl]);
            }

            return $mediaUrl;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return null;
        }
    }

    public function paginateBasicDisplay(array $request = []): void
    {
        $this->handleTokenExpiration();

        $this->getAllFiles();
    }

    public function getAllFilesBasicDisplay(string $endpoint = '', $i = 1): void
    {
        $options = [];

        if ($endpoint === '') {
            $endpoint = config('instagramBasicDisplay.query_base_url') . '/me/media';
            $options = [
                'query' => [
                    'fields'       => config('instagramBasicDisplay.fields.media'),
                    'access_token' => $this->service->access_token,
                    'limit'        => config('instagramBasicDisplay.per_page'),
                ],
            ];
        }

        try {
            $response = $this->getResponse($endpoint, $options);
            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (isset($data['data'])) {
                $files = $data['data'];

                // Handle item children
                foreach ($files as $item) {
                    $children = $this->getItemChildren($item);
                    $files = [...$files, ...$children];
                }

                $this->dispatch($files, 'Page ' . $i);
            }

            if ($nextUrl = data_get($data, 'paging.next')) {
                $this->getAllFiles($nextUrl, ++$i);
            }
        } catch (GuzzleException $e) {
            $this->httpStatus = $e->getCode();
            logger()->error($e->getMessage());
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }
            logger()->error($e->getMessage());
        }
    }

    private function handleTokenExpirationBasicDisplay(): void
    {
        if ($this->service?->expires && $this->checksForRefreshTokenExpiry($this->service->expires)) {
            try {
                $response = $this->client->get(config('instagramBasicDisplay.query_base_url') . '/refresh_access_token', [
                    'query' => [
                        'grant_type'   => 'ig_refresh_token',
                        'access_token' => $this->service->access_token,
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                $updates = $this->storeTokenBasicDisplay($data, true);

                $updatesToApply = collect($updates)
                    ->only(['access_token', 'expires', 'refresh_token'])
                    ->toArray();

                $this->service->update($updatesToApply);
            } catch (GuzzleException|Exception $e) {
                // Tokens that have not been refreshed in 60 days will expire and can no longer be refreshed.
                if ($e->getCode() === 401) {
                    $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
                }
                $this->log($e->getMessage());
            }
        }
    }
}
