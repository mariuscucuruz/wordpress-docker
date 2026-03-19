<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds\Service;

use Exception;
use Generator;
use Carbon\Carbon;
use RuntimeException;
use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Enums\AssetType;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\Integrations\TikTokAds\DTOs\AdData;
use MariusCucuruz\DAMImporter\Integrations\TikTokAds\Http\Client;
use GuzzleHttp\Promise\PromiseInterface;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Integrations\TikTokAds\DTOs\ImageData;
use MariusCucuruz\DAMImporter\Integrations\TikTokAds\DTOs\VideoData;
use MariusCucuruz\DAMImporter\Integrations\TikTokAds\DTOs\AdGroupData;
use MariusCucuruz\DAMImporter\Integrations\TikTokAds\DTOs\CampaignData;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;

class TikTokAdsService
{
    use Loggable;

    public const string DEFAULT_DATE_FORMAT = 'Y-m-d H:i:s';

    public ?string $accessToken = null;

    public ?string $refreshToken = null;

    public Client $client;

    public function __construct(public ?Service $service)
    {
        $this->accessToken = $this->service?->access_token ?? null;

        $this->client = new Client(
            service: $this->service ?? null,
            accessToken: $this->accessToken,
        );
    }

    public function makeAuthUrl(?string $state = null): string
    {
        throw_if(empty($state), InvalidSettingValue::class, 'No auth state provided');

        return $this->client->makeAuthUrl($state);
    }

    public function fetchAccessTokens(?string $theCode = null): array
    {
        throw_if(empty($theCode), CouldNotGetToken::class, 'Code not found in request');

        try {
            $response = $this->client->requestAccessToken($theCode);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error', null, $e->getTrace());

            throw new CouldNotGetToken($e->getMessage());
        }

        $tokensResponse = (array) data_get($response, 'data', []);
        $advertiserIds = data_get($tokensResponse, 'advertiser_ids', []);
        $accessToken = data_get($tokensResponse, 'access_token');
        $refreshToken = data_get($tokensResponse, 'refresh_token');

        if (empty($tokensResponse) || empty($accessToken)) {
            if ($reason = (string) data_get($response, 'message')) {
                $this->log("Failed to get access token: {$reason}", 'error', null, $response);
            }

            throw new CouldNotGetToken('Invalid token received ' . ($reason ?? null));
        }

        $this->accessToken = $accessToken;
        $accessTokenExpireTtl = (int) data_get($tokensResponse, 'expires_in', 86400);
        $accessTokenExpires = now()->addSeconds($accessTokenExpireTtl);

        $this->refreshToken = $refreshToken;
        $refreshTokenExpireTtl = (int) data_get($tokensResponse, 'refresh_token_expires_in', 31536000);
        $refreshTokenExpires = now()->addSeconds($refreshTokenExpireTtl);

        if ($this->service instanceof Service) {
            $this->service
                ->setMetaRequest([
                    'folder_ids' => array_values($advertiserIds),
                    'metadata'   => array_map(fn ($id) => ['folder_id' => $id], $advertiserIds),
                ])
                ->updateQuietly([
                    'access_token'             => $this->accessToken,
                    'expires'                  => $accessTokenExpires,
                    'refresh_token'            => $this->refreshToken,
                    'refresh_token_ttl'        => $refreshTokenExpireTtl,
                    'refresh_token_expires_at' => $refreshTokenExpires->format(self::DEFAULT_DATE_FORMAT),
                    'refresh_token_updated_at' => now(),
                    'created_at'               => now(),
                    'updated_at'               => now(),
                ]);

            $this->service->refresh();
        }

        return [
            'response_type'            => data_get($response, 'response_type'),
            'access_token'             => $this->accessToken,
            'expires'                  => $accessTokenExpireTtl,
            'refresh_token'            => $this->refreshToken,
            'refresh_token_ttl'        => $refreshTokenExpireTtl,
            'refresh_token_expires_at' => $refreshTokenExpires->format(self::DEFAULT_DATE_FORMAT),
            'refresh_token_updated_at' => now()->format(self::DEFAULT_DATE_FORMAT),
            'created'                  => now()->format(self::DEFAULT_DATE_FORMAT),
        ];
    }

    public function handleTokenExpiration(): void
    {
        $serviceAuth = $this->service?->oAuthToken();

        if (empty($serviceAuth)) {
            $this->log('TikTok Ads has no tokens', 'critical', null, [$this->service?->toArray()]);

            throw new CouldNotGetToken('TikTok Ads has no tokens');
        }

        if ($serviceAuth->valid()) {
            $this->accessToken = $serviceAuth->accessToken;
            $this->refreshToken = $serviceAuth->refreshToken;

            return;
        }

        if ($serviceAuth->expired() && $serviceAuth->canRefresh()) {
            if ($tokensResponse = $this->client->refreshAccessToken($serviceAuth->refreshToken)) {
                $accessToken = data_get($tokensResponse, 'access_token');
                $accessTokenExpireTtl = (int) data_get($tokensResponse, 'expires_in', 86400);
                $accessTokenExpires = now()->addSeconds($accessTokenExpireTtl);
                $refreshToken = data_get($tokensResponse, 'refresh_token');
                $refreshTokenExpireTtl = (int) data_get($tokensResponse, 'refresh_token_expires_in', 31536000);
                $refreshTokenExpires = now()->addSeconds($refreshTokenExpireTtl);

                $this->service?->update([
                    'access_token'             => $accessToken,
                    'expires'                  => $accessTokenExpires,
                    'refresh_token'            => $refreshToken,
                    'refresh_token_ttl'        => $refreshTokenExpires->format(self::DEFAULT_DATE_FORMAT) ?? null,
                    'refresh_token_expires_at' => $refreshTokenExpires ?? null,
                    'refresh_token_updated_at' => now(),
                    'created_at'               => now(),
                    'updated_at'               => now(),
                ]);

                $this->accessToken = $accessToken;
                $this->refreshToken = $refreshToken;

                return;
            }
        }

        $this->service?->update(['status' => IntegrationStatus::UNAUTHORIZED]);

        $this->service?->refresh();
    }

    public function fetchUser(?string $apiToken = null): array
    {
        $getUser = $this->client->getUserInfo($apiToken ?? $this->service?->access_token);
        $userdata = data_get($getUser, 'data', []);

        throw_if(empty($userdata), RuntimeException::class, 'User Not Found');

        return $userdata;
    }

    public function fetchAdvertisers(): array
    {
        $getAdvertisers = $this->client->getAllAdvertisers();
        throw_if(empty($getAdvertisers), RuntimeException::class, 'Advertisers Not Found');

        return $getAdvertisers;
    }

    /** @return string[] */
    public function resolveAdvertisersId(?array $payload): array
    {
        $storedIds = data_get($payload, 'folder_ids', []); // start sync
        $folderId = data_get($payload, 'folder_id'); // select folders

        if (is_array($storedIds) && filled($storedIds)) {
            $advertiserIds = $storedIds;
        } elseif (filled($folderId) && $folderId !== 'root') {
            $advertiserIds = is_array($folderId) ? $folderId : [$folderId];
        } else {
            $getAdvertisers = $this->client->getAllAdvertisers();
            $advertiserIds = array_column($getAdvertisers, 'advertiser_id');
        }

        throw_if(empty($advertiserIds), RuntimeException::class, 'Fetched 0 Advertisers');

        return array_filter($advertiserIds);
    }

    /** @return CampaignData[] */
    public function fetchCampaignsForAdvertiser(string|int $advertiserId): array
    {
        $campaigns = [];

        foreach ($this->client->getCampaigns($advertiserId) as $campaign) {
            $campaigns[] = CampaignData::fromArray([
                'id'            => data_get($campaign, 'campaign_id'),
                'advertiser_id' => data_get($campaign, 'advertiser_id') ?? $advertiserId,
                'name'          => data_get($campaign, 'campaign_name'),
                'status'        => data_get($campaign, 'operation_status') . ':' . data_get($campaign, 'secondary_status'),
                'meta'          => json_decode(json_encode($campaign), true),
            ]);
        }

        return $campaigns;
    }

    /** @return AdGroupData[] */
    public function fetchAdGroupsInCampaign(string|int $advertiserId, string|int $campaignId): ?array
    {
        $adGroups = [];

        foreach ($this->client->getAdGroups($advertiserId, $campaignId) as $adGroup) {
            $adGroups[] = AdGroupData::fromArray([
                'id'            => data_get($adGroup, 'adgroup_id'),
                'advertiser_id' => data_get($adGroup, 'advertiser_id') ?? $advertiserId,
                'campaign_id'   => data_get($adGroup, 'campaign_id') ?? $campaignId,
                'status'        => (data_get($adGroup, 'operation_status') . ':' . data_get($adGroup, 'secondary_status')),
                'name'          => data_get($adGroup, 'adgroup_name') ?? data_get($adGroup, 'campaign_name'),
                'keywords'      => data_get($adGroup, 'keywords'),
                'age_groups'    => data_get($adGroup, 'age_groups'),
                'placements'    => data_get($adGroup, 'placements'),
                'budget_mode'   => data_get($adGroup, 'budget_mode'),
                'start_date'    => data_get($adGroup, 'schedule_start_time') ? Carbon::parse(data_get($adGroup, 'schedule_start_time')) : null,
                'end_date'      => data_get($adGroup, 'schedule_end_time') ? Carbon::parse(data_get($adGroup, 'schedule_end_time')) : null,
                'created_date'  => data_get($adGroup, 'create_time') ? Carbon::parse(data_get($adGroup, 'create_time')) : null,
                'meta'          => json_decode(json_encode($adGroup), true),
            ]);
        }

        return $adGroups;
    }

    /** @return AdData[] */
    public function fetchAdsInGroup(
        string|int $advertiserId,
        string|int $adGroupId,
        null|string|int $campaignId = null
    ): array {
        $ads = [];

        foreach ($this->client->getAdsInGroup($advertiserId, $adGroupId) as $ad) {
            if (empty(data_get($ad, 'ad_id'))) {
                continue;
            }

            $adId = data_get($ad, 'ad_id');

            $ads[] = AdData::fromArray(array_filter([
                'id'                => $adId,
                'advertiser_id'     => data_get($ad, 'advertiser_id') ?? $advertiserId,
                'ad_group_id'       => data_get($ad, 'adgroup_id') ?? $adGroupId,
                'campaign_id'       => data_get($ad, 'campaign_id') ?? $campaignId,
                'identity_id'       => data_get($ad, 'identity_id'),
                'status'            => (data_get($ad, 'operation_status') . ':' . data_get($ad, 'secondary_status')) ?? null,
                'name'              => data_get($ad, 'display_name') ?? data_get($ad, 'ad_name'),
                'ad_format'         => data_get($ad, 'ad_format'),
                'ad_text'           => data_get($ad, 'ad_text'),
                'ad_texts'          => data_get($ad, 'ad_texts'),
                'landing_page_urls' => data_get($ad, 'landing_page_urls'),
                'video_id'          => data_get($ad, 'video_id'),
                'image_ids'         => data_get($ad, 'image_ids', []),
                'profile_image_url' => data_get($ad, 'profile_image_url'),
                'start_date'        => data_get($ad, 'schedule_start_time') ?? null,
                'end_date'          => data_get($ad, 'schedule_end_time') ?? null,
                'updated_date'      => data_get($ad, 'modify_time') ? Carbon::parse(data_get($ad, 'modify_time')) : null,
                'created_date'      => data_get($ad, 'create_time') ? Carbon::parse(data_get($ad, 'create_time')) : null,
                'meta'              => [
                    'data' => $ad,
                    'raw'  => $this->client->getSingleAd($advertiserId, $adId),
                ],
            ]));
        }

        return $ads;
    }

    /**
     * @note Ads may have many images and up to 1 video, or none.
     *
     * @return (VideoData|ImageData)[]
     */
    public function fetchAssetsInAd(AdData $ad): array
    {
        $assets = [];
        $adCoverImg = data_get($ad, 'thumbnail') ?? data_get($ad, 'image_urls.0');

        if (filled($ad->videoId) && $videoMeta = $this->client->getSingleVideo($ad->advertiserId, $ad->videoId)) {
            $assets[] = VideoData::fromArray([
                'file_id'       => data_get($videoMeta, 'video_id') ?? $ad->videoId,
                'ad_id'         => $ad->id,
                'ad_group_id'   => $ad->adGroupId,
                'campaign_id'   => $ad->campaignId,
                'advertiser_id' => $ad->advertiserId,
                'signature'     => data_get($videoMeta, 'signature'),
                'file_name'     => data_get($videoMeta, 'file_name') ?? str()->random(10) . '.mp4',
                'title'         => data_get($videoMeta, 'title') ?? data_get($videoMeta, 'file_name'),
                'url'           => data_get($videoMeta, 'preview_url'),
                'thumbnail'     => data_get($videoMeta, 'video_cover_url') ?? $adCoverImg,
                'extension'     => data_get($videoMeta, 'format', 'mp4'),
                'duration'      => data_get($videoMeta, 'duration'),
                'size'          => data_get($videoMeta, 'size'),
                'height'        => data_get($videoMeta, 'height'),
                'width'         => data_get($videoMeta, 'width'),
                'created_date'  => data_get($videoMeta, 'create_time') ? Carbon::parse(data_get($videoMeta, 'create_time')) : null,
                'updated_date'  => data_get($videoMeta, 'modify_time') ? Carbon::parse(data_get($videoMeta, 'modify_time')) : null,
                'meta'          => compact('videoMeta', 'ad'),
            ]);
        }

        foreach ($ad->imageIds as $imgId) {
            $imageMeta = $this->client->getSingleImage($ad->advertiserId, $imgId) ?? [];
            $downloadUrl = data_get($imageMeta, 'image_url');

            if (empty($downloadUrl)) {
                continue;
            }

            $assets[] = ImageData::fromArray([
                'file_id'       => data_get($imageMeta, 'image_id') ?? $imgId,
                'ad_id'         => $ad->id,
                'ad_group_id'   => $ad->adGroupId,
                'campaign_id'   => $ad->campaignId,
                'advertiser_id' => $ad->advertiserId,
                'signature'     => data_get($imageMeta, 'signature'),
                'file_name'     => data_get($imageMeta, 'file_name') ?? str()->random(10) . '.jpeg',
                'title'         => data_get($imageMeta, 'file_name') ?? $imgId,
                'url'           => $downloadUrl ?? $adCoverImg,
                'thumbnail'     => $downloadUrl ?? $adCoverImg,
                'extension'     => data_get($imageMeta, 'format', 'jpeg'),
                'size'          => data_get($imageMeta, 'size'),
                'height'        => data_get($imageMeta, 'height'),
                'width'         => data_get($imageMeta, 'width'),
                'created_date'  => data_get($imageMeta, 'create_time') ? Carbon::parse(data_get($imageMeta, 'create_time')) : null,
                'updated_date'  => data_get($imageMeta, 'modify_time') ? Carbon::parse(data_get($imageMeta, 'modify_time')) : null,
                'meta'          => compact('imageMeta', 'ad'),
            ]);
        }

        return array_filter($assets);
    }

    /** @return Generator<ImageData|VideoData> */
    public function fetchAllAssetsForAdvertiser(string $advertiserId): Generator
    {
        $mapTypeToDto = [
            ImageData::class => AssetType::Image,
            VideoData::class => AssetType::Video,
        ];

        foreach ($mapTypeToDto as $assetDto => $assetType) {
            foreach (iterator_to_array($this->client->getAllAssetsOfType($assetType, $advertiserId)) as $assetMeta) {
                /** @var ImageData|VideoData $assetDto */
                yield $assetDto::makeFromHttpResponse($assetMeta) ?? null;
            }
        }
    }

    public function streamVideo(string $videoId, string $advertiserId): PromiseInterface|Response
    {
        $videoMeta = $this->client->getSingleVideo($advertiserId, $videoId);
        $videoUrl = data_get($videoMeta, 'preview_url');

        throw_if(empty($videoUrl), CouldNotDownloadFile::class, "Cannot get video {$videoId} for {$advertiserId}");

        return Http::timeout(60)
            ->connectTimeout(15)
            ->maxRedirects(10)
            ->get($videoUrl);
    }

    public function streamImage(string $imageId, string $advertiserId): PromiseInterface|Response
    {
        $imagMeta = $this->client->getSingleImage($advertiserId, $imageId);
        $imageUrl = data_get($imagMeta, 'image_url');

        throw_if(empty($imageUrl), CouldNotDownloadFile::class, "Cannot get image {$imageId} for {$advertiserId}");

        return Http::timeout(60)
            ->connectTimeout(15)
            ->maxRedirects(10)
            ->get($imageUrl);
    }

    public function getThisUrl(?string $url): null|PromiseInterface|Response
    {
        if (empty($url)) {
            return null;
        }

        return $this->client->getUrl($url);
    }
}
