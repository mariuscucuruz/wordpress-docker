<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds\Http;

use Throwable;
use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Enums\AssetType;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\Integrations\TikTokAds\TikTokAds;
use GuzzleHttp\Promise\PromiseInterface;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Http\Client\PendingRequest;
use MariusCucuruz\DAMImporter\Integrations\TikTokAds\Enums\GrantType;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotQuery;
use MariusCucuruz\DAMImporter\Traits\ServiceRateLimiter;

class Client
{
    use Loggable, ServiceRateLimiter;

    public const string API_CLIENT_NAME = 'Medialake TikTok Ads API Client/1.0';

    public const int API_MAX_REDIRECTS = 10;

    public const int API_REQUEST_TIMEOUT = 30;

    public const string API_AUTH_HEADER = 'Access-Token';

    public const int REQUEST_TIMEFRAME_SECONDS = 60;

    public const int DEFAULT_PAGE_SIZE = 100;

    public string $redirectUri;

    protected ?string $baseUrl = null;

    private ?string $clientKey = null;

    private ?string $clientSecret = null;

    private array $clientScopes = [];

    public function __construct(public ?Service $service = null, public ?string $accessToken = null)
    {
        $this->startLog();

        if ($configuration = TikTokAds::loadConfiguration()) {
            $this->baseUrl = data_get($configuration, 'base_url');
            $this->clientKey = data_get($configuration, 'client_key');
            $this->clientSecret = data_get($configuration, 'client_secret');
            $this->clientScopes = data_get($configuration, 'client_scopes', []);
            $this->redirectUri = data_get($configuration, 'redirect_uri') ?? url('tiktokads-redirect', true);
        }

        $this->secondsToCooldown(self::REQUEST_TIMEFRAME_SECONDS);
    }

    public function makeAuthUrl(?string $state = null): string
    {
        $queryParams = http_build_query([
            'response_type' => 'code',
            'state'         => $state,
            'app_id'        => $this->clientKey,
            'redirect_uri'  => $this->redirectUri,
            'scope'         => implode(',', $this->clientScopes),
        ]);

        return filter_var("https://business-api.tiktok.com/portal/auth?{$queryParams}", FILTER_SANITIZE_URL);
    }

    public function requestAccessToken(string $code): array
    {
        return $this->apiClient()
            ->post('/oauth2/access_token/', [
                'app_id'     => $this->clientKey,
                'secret'     => $this->clientSecret,
                'auth_code'  => $code,
                'grant_type' => GrantType::AUTHORIZATION_CODE->value,
            ])->json();
    }

    public function refreshAccessToken(?string $refreshToken): array
    {
        $refreshToken ??= $this->service?->refresh_token;

        return $this->apiClient($this->accessToken)->asForm()
            ->post('/oauth/token/', [
                'client_key'    => $this->clientKey,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type'    => GrantType::REFRESH_TOKEN->value,
            ])->json();
    }

    public function getUserInfo(?string $apiToken): ?array
    {
        try {
            return $this->apiClient($apiToken ?? $this->accessToken)
                ->get('/user/info/')
                ->json();
        } catch (Throwable $e) {
            $this->log("Invalid user response: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return null;
    }

    public function getAllAdvertisers(): ?array
    {
        try {
            $response = $this->apiClient($this->accessToken)
                ->get('/oauth2/advertiser/get/', [
                    'app_id' => $this->clientKey,
                    'secret' => $this->clientSecret,
                ])->json();

            if ($reqErr = data_get($response, 'error', [])) {
                $this->log('fetch Advertisers failed:' . data_get($reqErr, 'message'), 'warn', null, $reqErr);
            }

            return (array) data_get($response, 'data.list', []);
        } catch (Throwable $e) {
            $this->log("fetch Ads failed: {$e->getMessage()}", 'error');
        }

        return null;
    }

    public function getAdvertiserInfo(int|string $advertiserId): ?array
    {
        try {
            $result = $this->apiClient($this->accessToken)
                ->get('/oauth2/advertiser/get/', [
                    'advertiser_id' => $advertiserId,
                    'app_id'        => $this->clientKey,
                    'secret'        => $this->clientSecret,
                ])->json();

            return (array) data_get($result, 'data.list');
        } catch (Throwable $e) {
            $this->log("singleAd failed: {$e->getMessage()}", 'error');
        }

        return null;
    }

    public function getCampaigns(int|string $advertiserId): array
    {
        try {
            $result = $this->apiClient($this->accessToken)
                ->get('/campaign/get/', [
                    'advertiser_id'  => $advertiserId,
                    'advertiser_ids' => [(string) $advertiserId],
                ])->json();

            return (array) data_get($result, 'data.list', []);
        } catch (Throwable $e) {
            $this->log('getCampaigns failed: ' . $e->getMessage(), 'error');
        }

        return [];
    }

    /** https://business-api.tiktok.com/portal/docs?id=1739314558673922 */
    public function getAdGroups(int|string|null $advertiserId = null, string|int|null $campaignId = null): array
    {
        $filters = [
            // ...$this->getDefaultFieldsFor('adGroup'),
            'advertiser_id' => $advertiserId,
        ];

        if (filled($campaignId)) {
            $filters['campaign_ids'] = [(string) $campaignId];
        }

        try {
            $result = $this->apiClient($this->accessToken)
                ->get('/adgroup/get/', $filters)
                ->json();

            return (array) data_get($result, 'data.list', []);
        } catch (Throwable $e) {
            $this->log('getAdGroups failed: ' . $e->getMessage(), 'error', null, $e->getTrace());
        }

        return [];
    }

    public function getAdsInGroup(int|string $advertiserId, int|string $adgroupId): ?array
    {
        $filters = [
            // ...$this->getDefaultFieldsFor('adGroup'),
            'advertiser_id' => $advertiserId,
        ];

        if (filled($adgroupId)) {
            $filters['adgroup_ids'] = [(string) $adgroupId];
        }

        try {
            $result = $this->apiClient($this->accessToken)
                ->get('/ad/get/', $filters)
                ->json('data.list', []);

            return array_filter($result, fn (array $ad) => (string) data_get($ad, 'adgroup_id') === (string) $adgroupId);
        } catch (Throwable $e) {
            $this->log("getAdsInGroup failed: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return null;
    }

    /** https://business-api.tiktok.com/portal/docs?id=1735735588640770 */
    public function getSingleAd(int|string $advertiserId, int|string $adId): ?array
    {
        try {
            $result = $this->apiClient($this->accessToken)
                ->get('/ad/get/', [
                    'advertiser_id' => $advertiserId,
                    'ad_ids'        => $adId,
                ])->json('data.list', []);

            return array_filter($result, fn (array $ad) => (string) data_get($ad, 'ad_ids') === (string) $adId);
        } catch (Throwable $e) {
            $this->log("getSingleAd failed: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return null;
    }

    public function getAllAssetsOfType(
        AssetType $assetType,
        int|string $advertiserId,
        ?int $batchSize = self::DEFAULT_PAGE_SIZE
    ): \Generator {
        $endpointMap = [
            'video' => '/file/video/ad/search/',
            'image' => '/file/image/ad/search/',
        ];

        $url = data_get($endpointMap, $assetType->value);
        throw_unless($url, CouldNotQuery::class, "Type not supported ({$assetType->value}).");

        $page = 1;
        $total = null;
        $count = 0;

        try {
            do {
                $result = $this->fetchAssetsWithRetry($url, $advertiserId, $page, $batchSize);
                $items = (array) data_get($result, 'data.list', []);
                $total = $total ?? (int) data_get($result, 'data.page_info.total_number', 0);

                foreach ($items as $item) {
                    yield $item;
                    $count++;

                    if ($count >= 10) {
                        return;
                    }
                }

                $hasMore = $count < $total && ! empty($items);
                $page++;
            } while ($hasMore);
        } catch (Throwable $e) {
            $this->log("fetching all {$assetType->value}s failed: {$e->getMessage()}", 'error', null, $e->getTrace());
        }
    }

    private function fetchAssetsWithRetry(
        string $url,
        int|string $advertiserId,
        int $page,
        ?int $batchSize
    ): array {
        $maxRetries = config('tiktokads.max_asset_retry', 10);
        $attempt = 0;

        do {
            $result = $this->apiClient($this->accessToken)
                ->get($url, [
                    'advertiser_id' => $advertiserId,
                    'page'          => $page,
                    'page_size'     => $batchSize,
                ])->json();

            $items = data_get($result, 'data.list');
            $attempt++;
        } while (empty($items) && $attempt < $maxRetries);

        return $result ?? [];
    }

    public function getSingleVideo(int|string $advertiserId, null|int|string $videoId): ?array
    {
        if (empty($videoId)) {
            return null;
        }

        try {
            $result = $this->apiClient($this->accessToken)
                ->get("/file/video/ad/info/?advertiser_id={$advertiserId}&video_ids=[\"{$videoId}\"]")
                ->json();

            return data_get($result, 'data.list.0');
        } catch (Throwable $e) {
            $this->log("singleVideo failed: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return null;
    }

    /** https://business-api.tiktok.com/portal/docs?id=1740051721711618 */
    public function getSingleImage(int|string $advertiserId, int|string $imageId): ?array
    {
        try {
            $result = $this->apiClient($this->accessToken)
                ->get("/file/image/ad/info/?advertiser_id={$advertiserId}&image_ids=[\"{$imageId}\"]")
                ->json();

            return (array) data_get($result, 'data.list.0');
        } catch (Throwable $e) {
            $this->log("singleImage failed: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return null;
    }

    public function getUrl(string $reqUri): PromiseInterface|Response
    {
        if (! str_starts_with($reqUri, 'http')) {
            $reqUri = "{$this->baseUrl}/" . ltrim($reqUri, '/');
        }

        return $this->apiClient()->get($reqUri);
    }

    /** https://business-api.tiktok.com/portal/docs?id=1740027843211265 */
    protected function apiClient(?string $withBearerToken = null, ?bool $asJson = true): PendingRequest
    {
        $this->incrementAttempts(self::REQUEST_TIMEFRAME_SECONDS);

        $request = Http::baseUrl($this->baseUrl)
            ->withoutVerifying()
            ->withUserAgent(self::API_CLIENT_NAME)
            ->maxRedirects(self::API_MAX_REDIRECTS)
            ->timeout(self::API_REQUEST_TIMEOUT)
            ->throw();

        if ($asJson) {
            $request->asJson()->acceptJson();
        } else {
            $request->asForm();
        }

        if (filled($withBearerToken)) {
            $request->withHeader(self::API_AUTH_HEADER, $withBearerToken);
        }

        return $request;
    }

    private function getDefaultFieldsFor(string $entity): array
    {
        $defaultFields = config('tiktokads.default_fields', []);

        if ($fields = data_get($defaultFields, $entity)) {
            return ['fields' => implode(',', $fields)];
        }

        $this->log("No default fields for entity {$entity}.", 'warn');

        return [];
    }
}
