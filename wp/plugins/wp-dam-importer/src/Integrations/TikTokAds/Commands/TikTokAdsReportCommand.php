<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds\Commands;

use MariusCucuruz\DAMImporter\Models\Service;
use Illuminate\Console\Command;
use MariusCucuruz\DAMImporter\Integrations\TikTokAds\TikTokAds;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\SourceIntegration;

class TikTokAdsReportCommand extends Command
{
    use Loggable;

    protected $signature = 'tiktokads:report'
        . ' { --advertiserId= : The ID of advertiser account (optional) }'
        . ' { --serviceId= : The ID of the service (required) }';

    protected $description = 'Breakdown on screen an existing TikTok Ads integration.';

    private ?string $serviceId = null;

    private ?string $advertiserId = null;

    private bool $details = false;

    public function handle(): int
    {
        $this->startLog();

        $this->advertiserId = (string) ($this->option('advertiserId') ?? '');
        $this->serviceId = (string) ($this->option('serviceId') ?? '');

        if (empty($this->serviceId)) {
            $this->log('Required serviceId not provided.', 'error', null, $this->getOptions());
            $this->endLog();

            return self::INVALID;
        }

        /** @var Service|null $service */
        $service = Service::find($this->serviceId);

        if (! $service) {
            $this->log("Invalid service ID provided: {$this->serviceId}.", 'error');
            $this->endLog();

            return self::INVALID;
        }

        /** @var TikTokAds|SourceIntegration|null $package */
        $package = $service->package;

        if (! $package instanceof TikTokAds) {
            $this->log("Service {$service->name} does not have a TikTokAds package.", 'error');
            $this->endLog();

            return self::FAILURE;
        }

        $advertisers = $package->ttService->fetchAdvertisers();

        $normalized = [];

        foreach ($advertisers as $adv) {
            $data = (array) $adv;
            $id = $data['advertiser_id'] ?? null;
            $name = $data['advertiser_name'] ?? '';

            if (! $id) {
                $this->log('Skipped advertiser entry without advertiser_id.', 'warn');

                continue;
            }
            $normalized[] = ['id' => (string) $id, 'name' => (string) $name];
        }

        if (! empty($this->advertiserId)) {
            $normalized = array_values(array_filter($normalized, fn ($a) => is_array($a) && ($a['id'] ?? null) === $this->advertiserId
            ));

            if ($normalized === []) {
                $this->log("No advertiser found with ID {$this->advertiserId}.", 'error');
                $this->endLog();

                return self::INVALID;
            }
        }

        foreach ($normalized as $a) {
            $id = $a['id'];
            $name = $a['name'];

            // Assets
            $assetsIterable = $package->ttService->fetchAllAssetsForAdvertiser($id);

            $allAssets = match (true) {
                is_iterable($assetsIterable) => iterator_to_array($assetsIterable, false),
                is_array($assetsIterable)    => $assetsIterable,
                default                      => [],
            };

            $this->info("> Account {$name} #{$id} has " . count($allAssets) . ' assets in total:');

            foreach ($package->ttService->fetchCampaignsForAdvertiser($id) as $campaign) {
                $cName = $campaign->name ?? '';
                $cId = $campaign->id ?? '';
                $cStatus = $campaign->status ?? '';
                $this->info(" └- Campaign {$cName} #{$cId} [{$cStatus}]");

                $countAdGroups = 0;

                foreach ($package->ttService->fetchAdGroupsInCampaign($id, $cId) as $adGroup) {
                    $gName = $adGroup->name ?? '';
                    $gId = $adGroup->id ?? '';
                    $gStatus = $adGroup->status ?? '';

                    $this->info('  └-- ' . (++$countAdGroups) . ")  Ad Group {$gName}#{$gId}  [{$gStatus}]");

                    $countAds = 0;

                    // Ads
                    foreach ($package->ttService->fetchAdsInGroup($id, $gId) as $ad) {
                        $aName = $ad->name ?? '';
                        $aId = $ad->id ?? '';
                        $aStatus = $ad->status ?? '';

                        $images = isset($ad->imageIds) && is_countable($ad->imageIds) ? count($ad->imageIds) : 0;
                        $videos = ! empty($ad->videoId) ? 1 : 0;

                        $this->info('    └--- ' . (++$countAds) . ". Ad {$aName} #{$aId} [{$aStatus}]: {$images} images + {$videos} videos.");
                    }
                }
            }
        }

        $this->endLog();

        return self::SUCCESS;
    }
}
