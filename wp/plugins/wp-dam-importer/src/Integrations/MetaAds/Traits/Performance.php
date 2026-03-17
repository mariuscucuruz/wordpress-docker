<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Metaads\Traits;

use Exception;
use MariusCucuruz\DAMImporter\Models\PaidAd;
use MariusCucuruz\DAMImporter\Models\PaidAdset;
use Illuminate\Support\Str;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use MariusCucuruz\DAMImporter\Models\PaidCampaign;
use MariusCucuruz\DAMImporter\Models\PaidAdAccount;
use MariusCucuruz\DAMImporter\Models\PaidAdCreative;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\Integrations\Metaads\Enums\MetaAdsAdType;
use Symfony\Component\HttpFoundation\Response;
use MariusCucuruz\DAMImporter\Integrations\Metaads\DTOs\MetaAdMetricsDTO;
use MariusCucuruz\DAMImporter\Integrations\Metaads\DTOs\MetaAdCreativeDTO;
use MariusCucuruz\DAMImporter\Integrations\Metaads\DTOs\MetaAdMarketingStructureDTO;

trait Performance
{
    // @Note: Creative response bodies are not consistent or predictable depending on the many different ad types. Useful debugging logs are left commented out for debugging purposes with new data.
    public int $campaignCount = 0;

    public int $adsetCount = 0;

    public int $adCount = 0;

    public int $creativeCount = 0;

    public function syncMarketingCampaignStructure(array $queue = []): array
    {
        $adAccounts = $this->getSyncedFolders();

        if (empty($adAccounts)) {
            $this->log('No folders synced for service', 'warning', null, [
                'service_name' => $this->service->name,
                'service_id'   => $this->service->id,
            ]);

            return [];
        }

        $this->syncAdAccounts($adAccounts);

        if (empty($queue)) {
            $ids = [];

            $this->service->paidAdAccounts()
                ->orderBy('updated_at', 'asc')
                ->select('remote_identifier', 'id', 'updated_at')
                ->limit(config('metaads.batch_request_max_size'))
                ->each(function (PaidAdAccount $adAccount) use (&$queue, &$ids) {
                    $ids[] = $adAccount->id;
                    $queue[] = [
                        'remoteId' => $adAccount->remote_identifier,
                        'localId'  => $adAccount->id,
                        'after'    => null,
                    ];
                });

            if (empty($ids)) {
                $this->endLog();

                return [];
            }

            PaidAdAccount::whereIn('id', $ids)->update(['updated_at' => now()]);
        }

        return $this->syncAdAccountsTree($queue);
    }

    public function syncAdAccountsTree(array $queue = []): array
    {
        if (empty($queue)) {
            $this->log('No ad accounts to sync for service.', 'warning', null, [
                'service_name' => $this->service->name,
                'service_id'   => $this->service->id,
            ]);

            return [];
        }

        $this->log("Syncing ad accounts tree for service {$this->service->name}");
        $batchData = $this->batchRequestGraphApi($this->constructBatchRequest($queue)); // NOTE: Meta API allows a maximum of 50 requests per batch request

        if (empty($batchData)) {
            $this->log('No data returned for ad accounts batch request.', 'warning', null, [
                'service_name' => $this->service->name,
                'service_id'   => $this->service->id,
            ]);

            return [];
        }

        $paginatedQueue = [];

        foreach ($batchData as $i => $adAccountData) {
            if (data_get($adAccountData, 'code') !== Response::HTTP_OK) {
                $this->log('Ad account data request failed', 'error', null, ['message' => (string) data_get($adAccountData, 'body')]);

                continue;
            }

            $requestItem = $queue[$i] ?? null;
            $adAccountUuid = data_get($requestItem, 'localId');
            $remoteAdAccountIdentifier = data_get($requestItem, 'remoteId');

            $body = json_decode(data_get($adAccountData, 'body') ?? '{}', true);

            // Check for OAuth errors in batch item response
            if ($this->checkForOAuthError($body)) {
                break; // Stop processing if OAuth error detected
            }

            $data = data_get($body, 'data') ?? [];
            $paging = data_get($body, 'paging.next');

            if (filled($data)) {
                foreach ($data as $adItem) {
                    $metaAdDto = MetaAdMarketingStructureDTO::fromApi([
                        ...$adItem,
                        ...compact('adAccountUuid', 'remoteAdAccountIdentifier')]
                    );
                    $this->syncAdStructure($metaAdDto);
                }
            }

            if (filled($paging)) {
                parse_str($paging, $result);
                $after = data_get($result, 'after');

                if (filled($after)) {
                    $paginatedQueue[] = [
                        'remoteId' => $remoteAdAccountIdentifier,
                        'localId'  => $adAccountUuid,
                        'after'    => $after,
                    ];
                }
            }
        }

        if ($this->campaignCount > 0 || $this->adsetCount > 0 || $this->adCount > 0 || $this->creativeCount > 0) {
            $this->log("Synced: {$this->campaignCount} Campaigns, {$this->adsetCount} Adsets, {$this->adCount} Ads, {$this->creativeCount} Ad Creatives.");
        }

        return $paginatedQueue;
    }

    public function syncAdStructure(MetaAdMarketingStructureDTO $metaAdDto): void
    {
        $campaign = $adset = $ad = null;

        if (filled($metaAdDto->remoteCampaignIdentifier)) {
            $campaign = PaidCampaign::updateOrCreate([
                'remote_identifier'  => $metaAdDto->remoteCampaignIdentifier,
                'service_id'         => $this->service->id,
                'paid_ad_account_id' => $metaAdDto->adAccountUuid,
            ], [
                'name'         => Str::limit($metaAdDto->campaignName, 250),
                'user_id'      => $this->service->user_id,
                'service_name' => $this->service->name,
                'team_id'      => $this->service->team_id,
            ]);

            if ($campaign->wasRecentlyCreated) {
                $this->campaignCount++;
            }
        }

        if (filled($metaAdDto->remoteAdsetIdentifier)) {
            $adset = PaidAdset::updateOrCreate([
                'remote_identifier'  => $metaAdDto->remoteAdsetIdentifier,
                'service_id'         => $this->service->id,
                'paid_ad_account_id' => $metaAdDto->adAccountUuid,
                'paid_campaign_id'   => $campaign?->id,
            ], [
                'name'         => Str::limit($metaAdDto->adsetName, 250),
                'user_id'      => $this->service->user_id,
                'service_name' => $this->service->name,
                'team_id'      => $this->service->team_id,
            ]);

            if ($adset->wasRecentlyCreated) {
                $this->adsetCount++;
            }
        }

        if (filled($metaAdDto->adRemoteIdentifier)) {
            $ad = PaidAd::updateOrCreate([
                'remote_identifier'  => $metaAdDto->adRemoteIdentifier,
                'service_id'         => $this->service->id,
                'paid_ad_account_id' => $metaAdDto->adAccountUuid,
                'paid_campaign_id'   => $campaign?->id,
                'paid_adset_id'      => $adset?->id,
            ], [
                'name'         => Str::limit($metaAdDto->adName, 250),
                'user_id'      => $this->service->user_id,
                'service_name' => $this->service->name,
                'team_id'      => $this->service->team_id,
                'ad_type'      => $metaAdDto->adType?->value,
            ]);

            if ($ad->wasRecentlyCreated) {
                $this->adCount++;
            }
        }

        if (filled($metaAdDto->creativeBody)) {
            $parentCreativeDto = MetaAdCreativeDTO::fromApi([
                ...$metaAdDto->creativeBody,
                ...[
                    'id'   => data_get($metaAdDto->creativeBody, 'id'),
                    'name' => data_get($metaAdDto->creativeBody, 'name'),
                ],
            ]);

            $childrenCreativesBodyArray = array_values(array_filter([
                ...MetaAdCreativeDTO::getChildAttachments($metaAdDto->creativeBody),
                ...MetaAdCreativeDTO::getDynamicVideos($metaAdDto->creativeBody),
                ...MetaAdCreativeDTO::getDynamicImages($metaAdDto->creativeBody),
            ]));

            $childrenCreativeDtos = [];

            foreach ($childrenCreativesBodyArray as $body) {
                $childDto = MetaAdCreativeDTO::fromApi([
                    ...$body,
                    ...[
                        'id'   => data_get($metaAdDto->creativeBody, 'id'),
                        'name' => data_get($metaAdDto->creativeBody, 'name'),
                    ],
                ]);

                if (is_null($childDto)) {
                    continue;
                }

                $childrenCreativeDtos[] = $childDto;
            }

            if (is_null($parentCreativeDto) && empty($childrenCreativeDtos)) {
                return;
            }

            foreach (array_filter([$parentCreativeDto, ...$childrenCreativeDtos]) as $dto) {
                $adCreativeModel = $this->syncAdCreative($dto, $metaAdDto->adAccountUuid, $metaAdDto->remoteAdAccountIdentifier);

                $ad->paidAdCreatives()->syncWithoutDetaching([$adCreativeModel->id => ['service_id' => $ad->service_id]]);

                if ($adCreativeModel->wasRecentlyCreated) {
                    $this->creativeCount++;
                }
            }
        }
    }

    public function syncAdCreative(MetaAdCreativeDTO $creative, string $adAccountUuid, string $remoteAdAccountIdentifier): PaidAdCreative
    {
        $remoteIdentifier = $creative->type === MetaAdsAdType::IMAGE
            ? str($remoteAdAccountIdentifier)->after('act_') . ':' . $creative->remoteIdentifier
            : $creative->remoteIdentifier;

        return PaidAdCreative::updateOrCreate(
            [
                'remote_identifier'  => $remoteIdentifier ?? $creative->socialPostIdentifier,
                'paid_ad_account_id' => $adAccountUuid,
            ],
            [
                'remote_parent_creative_id' => $creative->remoteParentCreativeIdentifier,
                'social_post_id'            => $creative->socialPostIdentifier,
                'name'                      => Str::limit($creative->name, 250),
                'creative_type'             => $creative->type?->value,
            ]);
    }

    public function constructBatchRequest(array $queue): array
    {
        $batchRequest = [];

        foreach ($queue as $adAccount) {
            $remoteId = data_get($adAccount, 'remoteId');

            if (empty($remoteId)) {
                $this->log('Skipping ad account with no remote ID', 'warning', null, [
                    'ad_account' => $adAccount,
                ]);

                continue;
            }

            $after = data_get($adAccount, 'after');

            $batchRequest[] = [
                'method'       => 'GET',
                'relative_url' => "{$remoteId}/ads?limit=" . config('metaads.limit_per_request')
                    . '&fields=' . config('metaads.ad_accounts_marketing_fields')
                    . "&after={$after}",
            ];
        }

        return $batchRequest;
    }

    public function constructInsightBatchRequest($queue = []): array
    {
        $batchRequest = [];

        foreach ($queue as $ad) {
            $remoteId = data_get($ad, 'remoteId');

            if (empty($remoteId)) {
                $this->log('Skipping ad with no remote ID', 'warning', null, [
                    'ad' => $ad,
                ]);

                continue;
            }

            $after = data_get($ad, 'after');

            $batchRequest[] = [
                'method'       => 'GET',
                'relative_url' => "{$remoteId}/insights?limit=" . config('metaads.limit_per_request')
                    . '&fields=' . config('metaads.performance_fields')
                    . '&date_preset=maximum'
                    . "&after={$after}",
            ];
        }

        return $batchRequest;
    }

    public function batchRequestGraphApi(array $batchRequest): array
    {
        $this->incrementAttempts();

        try {
            $response = Http::timeout(config('queue.timeout'))
                ->asForm()
                ->withToken($this->service->access_token)
                ->retry(3, 200)
                ->post(config('metaads.query_base_url') . config('metaads.version') . '/me', [
                    'batch'           => json_encode($batchRequest),
                    'include_headers' => false,
                ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error', null, $e->getTrace());

            return [];
        }

        if ($response->failed()) {
            $this->log('Batch request failed', 'error', null, ['response_body' => $response->body()]);

            return [];
        }

        $responseData = $response->json();

        // Check for OAuth errors in the response
        if ($this->checkForOAuthError($responseData)) {
            return [];
        }

        return $responseData;
    }

    private function checkForOAuthError(mixed $responseData): bool
    {
        $errorCodes = [190, 102, 458, 463, 467, 2500];

        if (is_string($responseData)) {
            $responseData = json_decode($responseData, true);
        }

        $error = data_get($responseData, 'error');

        if (! $error) {
            return false;
        }

        $errorType = data_get($error, 'type');
        $errorCode = data_get($error, 'code');

        if ($errorType === 'OAuthException' || in_array($errorCode, $errorCodes)) {
            $errorMessage = data_get($error, 'message', 'Unknown OAuth error');

            $this->log("OAuth error detected: {$errorMessage} (Code: {$errorCode})", 'error', null, [
                'error_type' => $errorType,
                'error_code' => $errorCode,
                'service_id' => $this->service->id,
            ]);

            $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);

            return true;
        }

        return false;
    }

    public function syncAdAccounts(array $syncedAdAccounts): void
    {
        $i = 0;

        foreach ($syncedAdAccounts as $adAccount) {
            $id = data_get($adAccount, 'folder_id');
            $name = str(data_get($adAccount, 'folder_name'))->after('Ad Account: ');

            $model = PaidAdAccount::updateOrCreate([
                'remote_identifier' => $id,
                'service_id'        => $this->service->id,
            ], [
                'name'         => Str::limit($name, 250),
                'user_id'      => $this->service->user_id,
                'service_name' => $this->service->name,
                'team_id'      => $this->service->team_id,
            ]);

            if ($model->wasRecentlyCreated) {
                $i++;
            }
        }

        if ($i > 0) {
            $this->log("Synced {$i} new ad accounts for service {$this->service->name}");
        }
    }

    public function pollPerformanceMetrics(array $queue): void
    {
        if (empty($queue)) {
            $this->log('No ad to sync for service', 'warning', null, [
                'service_name' => $this->service->name,
                'service_id'   => $this->service->id,
            ]);

            return;
        }

        $this->log("Syncing ad metrics for service {$this->service->name}");

        $batchData = $this->batchRequestGraphApi($this->constructInsightBatchRequest($queue));

        if (empty($batchData)) {
            $this->log('No data returned for ad metrics batch request', 'warning');

            return;
        }

        foreach ($batchData as $i => $adData) {
            if (data_get($adData, 'code') !== Response::HTTP_OK) {
                $this->log('Ad account data request failed: ', 'error', null, ['body' => data_get($adData, 'body')]);

                continue;
            }

            $requestItem = $queue[$i] ?? null;
            $ad = PaidAd::where('id', data_get($requestItem, 'localId'))->first();

            if (empty($ad)) {
                $this->log('Ad not found for local ID', 'error', null, ['request' => $requestItem]);

                continue;
            }

            $body = json_decode(data_get($adData, 'body') ?? '{}', true);

            // Check for OAuth errors in batch item response
            if ($this->checkForOAuthError($body)) {
                return; // Stop processing if OAuth error detected
            }

            $metricBody = data_get($body, 'data.0') ?? [];

            if (empty($metricBody)) {
                continue;
            }

            $metricDto = MetaAdMetricsDTO::fromApi($metricBody);

            $ad->paidAdMetrics()->updateOrCreate(
                ['paid_ad_id' => $ad->id],
                [
                    'reach'                              => $metricDto->reach,
                    'impressions'                        => $metricDto->impressions,
                    'views'                              => $metricDto->views,
                    'video_depth'                        => $metricDto->videoDepth,
                    'average_watch_time_in_milliseconds' => $metricDto->averageWatchTimeInMs,
                    'engagements'                        => $metricDto->engagements,
                    'shares'                             => $metricDto->shares,
                    'conversions'                        => $metricDto->conversions,
                    'comments'                           => $metricDto->comments,
                    'total_spend_in_pennies'             => $metricDto->totalSpendInPennies,
                    'currency'                           => $metricDto->currency?->value,
                    'click_through_rate'                 => $metricDto->clickThroughRate,
                    'cost_per_click_in_pennies'          => $metricDto->costPerClickInPennies,
                    'cost_per_mille_in_pennies'          => $metricDto->costPerMilleInPennies,
                    'cost_per_acquisition_in_pennies'    => $metricDto->costPerAcquisitionInPennies,
                    'conversion_rate'                    => $metricDto->conversionRate,
                    'engagement_rate'                    => $metricDto->engagementRate,
                    'return_on_ad_spend_in_pennies'      => $metricDto->returnOnAdSpendInPennies,
                    'frequency'                          => $metricDto->frequency,
                    'ad_start_time'                      => $metricDto->adStartTime,
                    'ad_end_time'                        => $metricDto->adEndTime,
                    'ad_type'                            => $ad->ad_type,
                ]);
        }
    }
}
