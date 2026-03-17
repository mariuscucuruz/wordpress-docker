<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient;

use Exception;
use GuzzleHttp\Psr7\Utils;
use MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Models\Account;
use MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Authentication\OAuth2Client;
use MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Authentication\FrameioAuthHelper;
use MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Http\Clients\FrameioHttpClientFactory;

class Frameio
{
    protected $accessToken;

    protected $client;

    protected $oAuth2Client;

    protected $app;

    public function __construct(FrameioApp $app, array $config = [])
    {
        $config = ['http_client_handler' => null, ...$config];

        $this->app = $app;

        $this->setAccessToken($app->getAccessToken());

        $httpClient = FrameioHttpClientFactory::make($config['http_client_handler']);

        $this->client = new FrameioClient($httpClient);
    }

    public function getAuthHelper()
    {
        return new FrameioAuthHelper($this->getOAuth2Client());
    }

    public function getOAuth2Client()
    {
        if (! $this->oAuth2Client instanceof OAuth2Client) {
            return new OAuth2Client($this->getApp(), $this->getClient());
        }

        return $this->oAuth2Client;
    }

    public function getApp()
    {
        return $this->app;
    }

    public function getClient()
    {
        return $this->client;
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

    public function getCurrentAccount()
    {
        $endpoint = 'me';

        try {
            return new Account(json_decode($this->callAPI('GET', $endpoint)->getBody()->getContents(), true));
        } catch (Exception $e) {
            logger()->error($e->getMessage());

            return [];
        }
    }

    public function download($asset_id, $uri)
    {
        $resource = Utils::tryFopen($uri, 'w');
        $stream = Utils::streamFor($resource);
        $params = ['sink' => $stream];

        $endpoint = "assets/{$asset_id}";
        $body = [
            'query' => [
                'include_deleted' => true,
                'type'            => 'file',
            ],
        ];

        try {
            $content = json_decode($this->callAPI('GET', $endpoint, $body)->getBody()->getContents(), true);

            if (isset($content['original'])) {
                $download_url = $content['original'];

                if ($this->callAPI('GET', $download_url, $params)->getStatusCode() == 200) {
                    return $uri;
                }
            }
        } catch (Exception $e) {
            logger()->error($e->getMessage());
        }
    }

    public function downloadThumb($thumbUrl)
    {
        try {
            $response = $this->callAPI('GET', $thumbUrl);

            if ($response->getStatusCode() == 200) {
                return $response->getBody()->getContents();
            }
        } catch (Exception $e) {
            logger()->error($e->getMessage());
        }

        return [];
    }

    public function search($accountId, $folders = false): array
    {
        $query = '';

        if (! $folders) {
            foreach (config('manager.meta.file_extensions') as $value) {
                $query .= "*.{$value}, ";
            }
        }

        try {
            return json_decode($this->callAPI('POST', 'search/library', [
                'json' => [
                    'account_id' => $accountId,
                    'q'          => $query,
                    'page_size'  => 100,
                    'page'       => 1,
                ],
            ])
                ->getBody()
                ->getContents(),
                true
            );
        } catch (Exception $e) {
            logger()->error($e->getMessage());
        }

        return [];
    }

    public function comments($assets_id): array
    {
        $endpoint = "assets/{$assets_id}/comments";
        $params = [
            'query' => [
                'include' => 'string',
            ],
        ];

        try {
            return json_decode($this->callAPI('GET', $endpoint, $params)
                ->getBody()
                ->getContents(),
                true
            );
        } catch (Exception $e) {
            logger()->error($e->getMessage());
        }

        return [];
    }

    public function comment($comment_id)
    {
        $endpoint = "comments/{$comment_id}";
        $params = [
            'query' => [
                'include' => 'string',
            ],
        ];

        try {
            return json_decode($this->callAPI('GET', $endpoint, $params)->getBody()->getContents(), true);
        } catch (Exception $e) {
            logger()->error($e->getMessage());
        }

        return [];
    }

    public function childAssets($asset_id)
    {
        $endpoint = "assets/{$asset_id}/children";
        $params = [
            'include_deleted' => true,
            'include'         => 'cover_asset',
        ];

        try {
            return json_decode($this->callAPI('GET', $endpoint, $params)->getBody()->getContents(), true);
        } catch (Exception $e) {
            logger()->error($e->getMessage());
        }

        return [];
    }

    public function callAPI($methods, $endpoint, array $params = [], array $headers = [])
    {
        return $this->sendRequest($endpoint, $methods, $params, $headers);
    }

    public function sendRequest($endpoint, $method, array $params = [], array $headers = [])
    {
        $accessToken = $this->getAccessToken();

        $request = new FrameioRequest($method, $endpoint, $accessToken, $params, $headers);

        return $this->getClient()->sendRequest($request);
    }
}
