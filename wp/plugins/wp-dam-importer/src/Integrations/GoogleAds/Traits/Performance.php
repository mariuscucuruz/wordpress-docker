<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\GoogleAds\Traits;

use Exception;
use MariusCucuruz\DAMImporter\Models\PaidAd;
use MariusCucuruz\DAMImporter\Enums\Currency;
use MariusCucuruz\DAMImporter\Models\PaidAdset;
use Illuminate\Support\Str;
use MariusCucuruz\DAMImporter\Models\PaidCampaign;
use MariusCucuruz\DAMImporter\Models\PaidAdAccount;
use MariusCucuruz\DAMImporter\Models\PaidAdCreative;
use Illuminate\Support\Facades\Validator;
use MariusCucuruz\DAMImporter\Integrations\GoogleAds\Enum\GoogleAdAdType;
use MariusCucuruz\DAMImporter\Integrations\GoogleAds\Enum\GoogleAdObjectType;
use MariusCucuruz\DAMImporter\Integrations\GoogleAds\DTOs\GoogleAdsMetricsDTO;

// @Note: Google Ads Performance has several edge cases.
// Performance Max (PM) campaigns do not contain ads, here we duplicate the Ad Group and capture metrics at this level,
// Outside of PM campaigns, a large number of ad types exist (34). https://developers.google.com/google-ads/api/reference/rpc/v20/AdTypeEnum.AdType
// There is no generalised way to query an ads media, it is specific to the ad type enum.
// As of V1 we will support PERFORMANCE_MAX Campaigns, alongside RESPONSIVE_DISPLAY_AD, VIDEO_RESPONSIVE_AD & IMAGE_AD types.
// We will expand to new ad types as the most popular ones are requested by customers.

trait Performance
{
    public array $campaignsMap = [];

    public array $adSetsMap = [];

    public array $adsMap = [];

    public array $syncedCounters = [];

    public function syncMarketingCampaignStructure(array $queue = []): array
    {
        $syncedCustomers = $this->getSyncedFolders();

        $this->syncedCounters = [
            'customers' => 0,
            'campaigns' => 0,
            'ad_sets'   => 0,
            'ads'       => 0,
            'creatives' => 0,
        ];

        do {
            $customer = array_pop($syncedCustomers);
            $objectType = GoogleAdObjectType::tryFrom(data_get($customer, 'site_drive_id'));

            if ($objectType === GoogleAdObjectType::CUSTOMER) {
                $this->updateOrCreateCustomer($customer);
            }

            if ($objectType === GoogleAdObjectType::MANAGER) {
                $managerChildrenFolders = $this->getManagerCustomers(data_get($customer, 'folder_id'));
                $managerChildrenEntries = array_map(
                    fn ($child) => [
                        'folder_id'     => $child['id'],
                        'folder_name'   => $child['rawName'],
                        'site_drive_id' => GoogleAdObjectType::CUSTOMER->value,
                    ],
                    $managerChildrenFolders
                );
                $syncedCustomers = [...$syncedCustomers, ...$managerChildrenEntries];
            }
        } while (filled($syncedCustomers));

        $customers = $this->service->paidAdAccounts;

        foreach ($customers as $customer) {
            $this->log("Starting marketing structure sync for Customer ID: {$customer->id}");

            $this->syncPerformanceMaxStructure($customer);
            $this->syncTraditionalCampaignStructure($customer);
        }

        $this->log(
            'Sync complete. Added: ' .
            "Customers: {$this->syncedCounters['customers']}, " .
            "Campaigns: {$this->syncedCounters['campaigns']}, " .
            "Ad Sets: {$this->syncedCounters['ad_sets']}, " .
            "Ads: {$this->syncedCounters['ads']}, " .
            "Creatives: {$this->syncedCounters['creatives']}."
        );

        return [];
    }

    public function syncTraditionalCampaignStructure(PaidAdAccount $customer): void
    {
        $this->log("Syncing Non Performance Max campaign structure for Customer ID: {$customer->id}");

        $query = $this->getTraditionalCampaignStructureQuery();
        $adGroupAds = $this->runPaginatedQuery($query, $customer->remote_identifier);

        foreach ($adGroupAds as $row) {
            $rowArray = json_decode($row->serializeToJsonString(), true);

            $campaignData = data_get($rowArray, 'campaign');
            $adGroupData = data_get($rowArray, 'adGroup');
            $adData = data_get($rowArray, 'adGroupAd.ad');

            $adType = data_get($adData, 'type');
            $adTypeEnum = GoogleAdAdType::tryFrom($adType);

            if (empty($adTypeEnum)) {
                $this->log("Google Ads Performance does not currently support ad type: {$adType}", 'warning');

                continue;
            }

            if (! in_array($adTypeEnum->value, GoogleAdAdType::supportedTypes())) {
                $this->log("Ad type {$adTypeEnum->value} is not supported.", 'warning');

                continue;
            }

            $campaign = $this->updateOrCreateCampaign($campaignData, $customer->id);

            if (! $campaign) {
                continue;
            }

            $adSet = $this->updateOrCreateAdSet($adGroupData, data_get($campaign, 'id'), $customer->id);

            if (! $adSet) {
                continue;
            }

            $ad = $this->updateOrCreateAd($adData, data_get($campaign, 'id'), data_get($adSet, 'id'), $customer->id);

            if (! $ad) {
                continue;
            }

            $assetIds = $this->getAssetsIdFromNonPerformanceMaxAdGroupAsResponse($adData);

            if (filled($assetIds)) {
                $query = $this->getAssetFromIdQuery($assetIds);
                $assets = $this->runPaginatedQuery($query, $customer->remote_identifier);

                foreach ($assets as $assetRow) {
                    $assetArray = json_decode($assetRow->serializeToJsonString(), true);
                    $asset = data_get($assetArray, 'asset');

                    if (filled($asset)) {
                        $this->updateOrCreateCreative($asset, $ad, $customer->id);
                    }
                }
            }
        }
    }

    public function getAssetsIdFromNonPerformanceMaxAdGroupAsResponse(array $adData): array
    {
        $assetIds = [];

        // RESPONSIVE_DISPLAY_AD asset key values
        $marketingImages = data_get($adData, 'responsiveDisplayAd.marketingImages', []);
        $squareMarketingImages = data_get($adData, 'responsiveDisplayAd.squareMarketingImages', []);
        $squareLogoImages = data_get($adData, 'responsiveDisplayAd.squareLogoImages', []);
        $youtubeVideos = data_get($adData, 'responsiveDisplayAd.youtubeVideos', []);

        // VIDEO_RESPONSIVE_AD asset key values
        $videos = data_get($adData, 'videoResponsiveAd.videos', []);

        // IMAGE_AD asset key values
        $imageAsset = data_get($adData, 'imageAd.imageAsset', []);

        $totalAssetArray = [
            ...$marketingImages,
            ...$squareMarketingImages,
            ...$squareLogoImages,
            ...$youtubeVideos,
            ...$videos,
            $imageAsset,
        ];

        foreach ($totalAssetArray as $asset) {
            if (filled(data_get($asset, 'asset'))) {
                $assetIds[] = Str::afterLast(data_get($asset, 'asset'), '/');
            }
        }

        return $assetIds;
    }

    public function syncPerformanceMaxStructure(PaidAdAccount $customer): void
    {
        $this->log("Syncing Performance Max campaign structure for Customer ID: {$customer->id}");

        $query = $this->getPerformanceMaxStructureQuery();
        $assetRows = $this->runPaginatedQuery($query, $customer->remote_identifier);

        foreach ($assetRows as $row) {
            $rowArray = json_decode($row->serializeToJsonString(), true);

            $campaignData = data_get($rowArray, 'campaign');
            $assetGroupData = data_get($rowArray, 'assetGroup');
            $assetData = data_get($rowArray, 'asset');

            $assetType = data_get($assetData, 'type');

            if (! in_array($assetType, ['VIDEO', 'IMAGE', 'YOUTUBE_VIDEO'])) {
                continue;
            }

            $campaign = $this->updateOrCreateCampaign($campaignData, $customer->id);

            if (! $campaign) {
                continue;
            }

            $adSet = $this->updateOrCreateAdSet($assetGroupData, data_get($campaign, 'id'), $customer->id);

            if (! $adSet) {
                continue;
            }

            $ad = $this->updateOrCreateAd($assetGroupData, data_get($campaign, 'id'), data_get($adSet, 'id'), $customer->id, true);

            if (! $ad) {
                continue;
            }

            $this->updateOrCreateCreative($assetData, $ad, $customer->id);
        }
    }

    public function updateOrCreateCustomer(array $customerData = []): ?PaidAdAccount
    {
        Validator::make($customerData, [
            'folder_id'   => 'required',
            'folder_name' => 'required|string',
        ])->validate();

        $customerId = data_get($customerData, 'folder_id');
        $this->log("Update or create Customer ID: {$customerId}");

        try {
            $query = $this->getCustomerInfoQuery();
            $customerRows = $this->runSingleQuery($query, $customerId);

            $firstRow = null;

            foreach ($customerRows as $row) {
                $firstRow = $row;

                break;
            }

            if ($firstRow === null) {
                $this->log('No customer data found in the response.');

                return null;
            }

            $customerInfo = json_decode($firstRow->getCustomer()->serializeToJsonString(), true);

            $customer = PaidAdAccount::updateOrCreate([
                'remote_identifier' => data_get($customerInfo, 'id'),
                'service_id'        => $this->service->id,
            ], [
                'name'         => data_get($customerInfo, 'descriptiveName'),
                'user_id'      => $this->service->user_id,
                'service_name' => $this->service->name,
                'team_id'      => $this->service->team_id,
            ]);

            if ($customer->wasRecentlyCreated) {
                $this->log('Created new PaidAdAccount for Customer ID: ' . data_get($customerInfo, 'id'));
                $this->syncedCounters['customers']++;
            }
        } catch (Exception $e) {
            $this->log('Error updating or creating customer: ' . $e->getMessage(), 'error');

            return null;
        }

        return $customer;
    }

    public function updateOrCreateCampaign(array $campaignData, string $localCustomerId): ?PaidCampaign
    {
        $remoteId = data_get($campaignData, 'id');

        if (isset($this->campaignsMap[$remoteId])) {
            return $this->campaignsMap[$remoteId];
        }

        try {
            $campaign = PaidCampaign::updateOrCreate(
                [
                    'remote_identifier'  => $remoteId,
                    'service_id'         => $this->service->id,
                    'paid_ad_account_id' => $localCustomerId,
                ],
                [
                    'name'         => data_get($campaignData, 'name'),
                    'user_id'      => $this->service->user_id,
                    'service_name' => $this->service->name,
                    'team_id'      => $this->service->team_id,
                ]
            );
        } catch (Exception $e) {
            $this->log("Failed to create or update Campaign with remote ID {$remoteId}: {$e->getMessage()}", 'error');

            return null;
        }

        if ($campaign->wasRecentlyCreated) {
            $this->log("Created new PaidCampaign for Campaign ID: {$remoteId}");
            $this->syncedCounters['campaigns']++;
        }

        return $this->campaignsMap[$remoteId] = $campaign;
    }

    public function updateOrCreateAdSet(array $adSetData, string $campaignId, string $localCustomerId): ?PaidAdset
    {
        $remoteId = data_get($adSetData, 'id');

        if (isset($this->adSetsMap[$remoteId])) {
            return $this->adSetsMap[$remoteId];
        }

        try {
            $adset = PaidAdset::updateOrCreate(
                [
                    'remote_identifier'  => $remoteId,
                    'paid_ad_account_id' => $localCustomerId,
                    'paid_campaign_id'   => $campaignId,
                    'service_id'         => $this->service->id,
                ],
                [
                    'name'         => data_get($adSetData, 'name'),
                    'user_id'      => $this->service->user_id,
                    'service_name' => $this->service->name,
                    'team_id'      => $this->service->team_id,
                ]
            );
        } catch (Exception $e) {
            $this->log("Failed to create or update AdSet with remote ID {$remoteId}: {$e->getMessage()}", 'error');

            return null;
        }

        if ($adset->wasRecentlyCreated) {
            $this->log("Created new PaidAdset for Ad Set ID: {$remoteId}");
            $this->syncedCounters['ad_sets']++;
        }

        return $this->adSetsMap[$remoteId] = $adset;
    }

    public function updateOrCreateAd(array $adData, string $campaignId, string $adsetId, string $localCustomerId, bool $isPerformanceMax = false): ?PaidAd
    {
        $remoteId = data_get($adData, 'id');

        if (isset($this->adsMap[$remoteId])) {
            return $this->adsMap[$remoteId];
        }

        try {
            $ad = PaidAd::updateOrCreate(
                [
                    'remote_identifier'  => $remoteId,
                    'paid_ad_account_id' => $localCustomerId,
                    'paid_campaign_id'   => $campaignId,
                    'paid_adset_id'      => $adsetId,
                    'service_id'         => $this->service->id,
                ],
                [
                    'name'         => data_get($adData, 'name'),
                    'user_id'      => $this->service->user_id,
                    'service_name' => $this->service->name,
                    'team_id'      => $this->service->team_id,
                    'ad_type'      => $isPerformanceMax ? 'PERFORMANCE_MAX' : data_get($adData, 'type'),
                ]
            );
        } catch (Exception $e) {
            $this->log("Failed to create or update Ad with remote ID {$remoteId}: {$e->getMessage()}", 'error');

            return null;
        }

        if ($ad->wasRecentlyCreated) {
            $this->log("Created new PaidAd for Ad ID: {$remoteId}");
            $this->syncedCounters['ads']++;
        }

        return $this->adsMap[$remoteId] = $ad;
    }

    public function updateOrCreateCreative(array $assetData, PaidAd $ad, string $localCustomerId): ?PaidAdCreative
    {
        $remoteId = data_get($assetData, 'id');

        try {
            $creative = PaidAdCreative::updateOrCreate(
                [
                    'remote_identifier'  => data_get($assetData, 'youtubeVideoAsset.youtubeVideoId') ?? $remoteId,
                    'paid_ad_account_id' => $localCustomerId,
                ],
                [
                    'name'          => data_get($assetData, 'name') ?? data_get($assetData, 'youtubeVideoAsset.youtubeVideoTitle'),
                    'creative_type' => data_get($assetData, 'type'),
                ]
            );

            $ad->paidAdCreatives()->syncWithoutDetaching([$creative->id => ['service_id' => $ad->service_id]]);

            if ($creative->wasRecentlyCreated) {
                $this->log("Created new PaidAdCreative for Asset ID: {$remoteId}");
                $this->syncedCounters['creatives']++;
            }
        } catch (Exception $e) {
            $this->log("Failed to create or update Creative with remote ID {$remoteId}: {$e->getMessage()}", 'error');

            return null;
        }

        return $creative;
    }

    public function getAssetFromIdQuery(array $ids): string
    {
        $idList = implode(', ', $ids);

        return <<<SQL
        SELECT
            asset.id,
            asset.name,
            asset.type,
            asset.image_asset.full_size.url,
            asset.youtube_video_asset.youtube_video_id,
            asset.youtube_video_asset.youtube_video_title
        FROM asset
        WHERE asset.id IN ({$idList})
        SQL;
    }

    public function getTraditionalCampaignStructureQuery(): string
    {
        // https:// developers.google.com/google-ads/api/fields/v18/ad_group_ad_query_builder
        return <<<'SQL'
        SELECT
            campaign.id,
            campaign.name,
            ad_group.id,
            ad_group.name,
            ad_group_ad.ad.id,
            ad_group_ad.ad.name,
            ad_group_ad.ad.type,

            -- Responsive Search Ad Assets
            ad_group_ad.ad.responsive_search_ad.headlines,
            ad_group_ad.ad.responsive_search_ad.descriptions,

            -- Responsive Display Ad Assets
            ad_group_ad.ad.responsive_display_ad.headlines,
            ad_group_ad.ad.responsive_display_ad.long_headline,
            ad_group_ad.ad.responsive_display_ad.descriptions,
            ad_group_ad.ad.responsive_display_ad.youtube_videos,
            ad_group_ad.ad.responsive_display_ad.marketing_images,
            ad_group_ad.ad.responsive_display_ad.square_marketing_images,
            ad_group_ad.ad.responsive_display_ad.logo_images,
            ad_group_ad.ad.responsive_display_ad.square_logo_images,

            -- Video Ad Assets
            ad_group_ad.ad.image_ad.image_asset.asset,
            ad_group_ad.ad.video_responsive_ad.headlines,
            ad_group_ad.ad.video_responsive_ad.long_headlines,
            ad_group_ad.ad.video_responsive_ad.descriptions,
            ad_group_ad.ad.video_responsive_ad.videos,

            -- App Ad Assets
            ad_group_ad.ad.app_ad.images,
            ad_group_ad.ad.app_ad.youtube_videos,
            ad_group_ad.ad.app_ad.html5_media_bundles,
            ad_group_ad.ad.app_engagement_ad.images,
            ad_group_ad.ad.app_engagement_ad.videos,
            ad_group_ad.ad.app_pre_registration_ad.images,
            ad_group_ad.ad.app_pre_registration_ad.youtube_videos,

            -- Demand Gen Ad Assets
            ad_group_ad.ad.demand_gen_carousel_ad.logo_image,
            ad_group_ad.ad.demand_gen_multi_asset_ad.marketing_images,
            ad_group_ad.ad.demand_gen_multi_asset_ad.square_marketing_images,
            ad_group_ad.ad.demand_gen_multi_asset_ad.portrait_marketing_images,
            ad_group_ad.ad.demand_gen_multi_asset_ad.logo_images,
            ad_group_ad.ad.demand_gen_product_ad.logo_image,
            ad_group_ad.ad.demand_gen_video_responsive_ad.videos,
            ad_group_ad.ad.demand_gen_video_responsive_ad.logo_images,

            -- Other Ad Type Assets
            ad_group_ad.ad.local_ad.marketing_images,
            ad_group_ad.ad.local_ad.videos,
            ad_group_ad.ad.local_ad.logo_images,
            ad_group_ad.ad.display_upload_ad.media_bundle,
            ad_group_ad.ad.legacy_responsive_display_ad.marketing_image,
            ad_group_ad.ad.legacy_responsive_display_ad.logo_image

        FROM ad_group_ad
        WHERE campaign.advertising_channel_type != 'PERFORMANCE_MAX'
        SQL;
    }

    public function getPerformanceMaxStructureQuery(): string
    {
        return <<<'SQL'
        SELECT
            campaign.id,
            campaign.name,
            campaign.status,
            asset_group.id,
            asset_group.name,
            asset_group.status,
            asset.id,
            asset.name,
            asset.type,
            asset.image_asset.full_size.url,
            asset.youtube_video_asset.youtube_video_id,
            asset.youtube_video_asset.youtube_video_title
        FROM
            asset_group_asset
        WHERE
            campaign.advertising_channel_type = 'PERFORMANCE_MAX'
    SQL;
    }

    public function getCustomerInfoQuery(): string
    {
        return <<<'SQL'
            SELECT
                customer.id,
                customer.descriptive_name
            FROM customer
            LIMIT 1
        SQL;
    }

    public function getPerformanceMaxAssetGroupMetricsQuery(string $id): string
    {
        return <<<SQL
        SELECT
          metrics.impressions,
          metrics.clicks,
          metrics.ctr,
          metrics.average_cpc,
          metrics.cost_micros,
          metrics.all_conversions,
          metrics.conversions,
          metrics.conversions_from_interactions_rate,
          metrics.conversions_value,
          metrics.conversions_value_per_cost,
          metrics.cost_per_conversion,
          metrics.value_per_conversion,
          metrics.interactions,
          metrics.interaction_rate
        FROM asset_group
        WHERE asset_group.id = {$id}
        SQL;
    }

    public function getAdMetricsQuery(string $adId): string
    {
        return <<<SQL
        SELECT
          metrics.impressions,
          metrics.clicks,
          metrics.ctr,
          metrics.average_cpc,
          metrics.cost_micros,
          metrics.all_conversions,
          metrics.conversions,
          metrics.conversions_from_interactions_rate,
          metrics.conversions_value,
          metrics.cost_per_conversion,
          metrics.value_per_conversion,
          metrics.interactions,
          metrics.interaction_rate
        FROM ad_group_ad
        WHERE ad_group_ad.ad.id = {$adId}
        SQL;
    }

    public function getCustomerCurrencyQuery(): string
    {
        return
            <<<'SQL'
                SELECT customer.currency_code
                FROM customer
            SQL;
    }

    public function getCustomerCurrencyCode(string $remoteCustomerId): ?string
    {
        try {
            $response = $this->runSingleQuery($this->getCustomerCurrencyQuery(), $remoteCustomerId);

            $customer = null;

            foreach ($response as $row) {
                $customer = $row;

                break;
            }

            if ($customer === null) {
                $this->log('No customer data found to retrieve currency code.', 'error');

                return null;
            }

            return $customer->getCustomer()->getCurrencyCode();
        } catch (Exception $e) {
            $this->log('Failed to fetch currency code: ' . $e->getMessage(), 'error');

            return null;
        }
    }

    public function pollPerformanceMetrics(array $queue): void
    {
        if (empty($queue)) {
            $this->log("No ad to sync for service {$this->service->name}", 'warning');

            return;
        }

        $this->log("Syncing ad metrics for service {$this->service->name}");
        $i = 0;

        foreach ($queue as $adData) {
            $remoteId = data_get($adData, 'remoteId');
            $localId = data_get($adData, 'localId');

            $ad = PaidAd::query()
                ->with('paidAdAccount')
                ->where('id', $localId)
                ->first();

            $customer = $ad->paidAdAccount;

            $currencyCode = $this->getCustomerCurrencyCode($customer->remote_identifier);
            $currencyEnum = Currency::tryFrom($currencyCode) ?? Currency::UNKNOWN;

            if (empty($ad)) {
                $this->log('Ad not found for local ID: ' . $localId, 'error');

                continue;
            }

            $query = $ad->ad_type === 'PERFORMANCE_MAX' ?
                $this->getPerformanceMaxAssetGroupMetricsQuery($remoteId) :
                $this->getAdMetricsQuery($remoteId);

            $response = $this->runSingleQuery($query, $customer->remote_identifier);
            $body = null;

            foreach ($response as $row) {
                $body = $row;

                break;
            }

            if ($body === null) {
                $this->log("No metrics data found for ad: {$remoteId}", 'warning');

                continue;
            }

            $bodyArray = json_decode($body->serializeToJsonString(), true);
            $metricsArray = data_get($bodyArray, 'metrics');

            if (empty($metricsArray)) {
                $this->log("No metrics data found for ad: {$remoteId}", 'warning');

                continue;
            }

            $metricDto = GoogleAdsMetricsDto::fromApi($metricsArray, $currencyEnum);

            $metricModel = $ad->paidAdMetrics()->create([
                //                'reach'                              => $metricDto->reach,
                //                'views'                              => $metricDto->views,
                //                'video_depth'                        => $metricDto->videoDepth,
                //                'average_watch_time_in_milliseconds' => $metricDto->averageWatchTimeInMs,
                //                'engagements'                        => $metricDto->engagements,
                //                'shares'                             => $metricDto->shares,
                //                'comments'                           => $metricDto->comments,
                //                'frequency'                          => $metricDto->frequency,
                //                'ad_start_time'                      => $metricDto->adStartTime,
                //                'ad_end_time'                        => $metricDto->adEndTime,
                //                'engagement_rate'                    => $metricDto->engagementRate,
                'impressions'                     => $metricDto->impressions,
                'conversions'                     => $metricDto->conversions,
                'total_spend_in_pennies'          => $metricDto->totalSpendInPennies,
                'currency'                        => $metricDto->currency?->value,
                'click_through_rate'              => $metricDto->clickThroughRate,
                'cost_per_click_in_pennies'       => $metricDto->costPerClickInPennies,
                'cost_per_mille_in_pennies'       => $metricDto->costPerMilleInPennies,
                'cost_per_acquisition_in_pennies' => $metricDto->costPerAcquisitionInPennies,
                'conversion_rate'                 => $metricDto->conversionRate,
                'return_on_ad_spend_in_pennies'   => $metricDto->returnOnAdSpendInPennies,
                'ad_type'                         => $ad->ad_type,
            ]);

            if ($metricModel->wasRecentlyCreated) {
                $this->log("Metrics synced for ad: {$remoteId}");
                $i++;
            }
        }
        $this->log("Synced {$i} metrics for service {$this->service->name}");
    }
}
