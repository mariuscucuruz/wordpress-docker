<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\GoogleAds\DTOs;

use MariusCucuruz\DAMImporter\Enums\Currency;

class GoogleAdsMetricsDTO
{
    //  https://developers.google.com/google-ads/api/fields/v20/ad_group_ad
    //  https://developers.google.com/google-ads/api/fields/v20/ad_group
    public function __construct(
        public ?int $impressions = null,
        public ?int $conversions = null,
        public ?float $clickThroughRate = null,
        public ?float $conversionRate = null,
        public ?int $totalSpendInPennies = null,
        public ?int $costPerClickInPennies = null,
        public ?int $costPerAcquisitionInPennies = null,
        public ?int $costPerMilleInPennies = null,
        public ?int $returnOnAdSpendInPennies = null,
        public ?Currency $currency = Currency::UNKNOWN,
    ) {}

    public static function fromApi(array $metrics, Currency $currency): self
    {
        // Note: WIP investigate adding further metrics.

        $costMicros = (int) ($metrics['costMicros'] ?? 0);
        $conversions = (float) ($metrics['conversions'] ?? 0);
        $impressions = isset($metrics['impressions']) ? (int) $metrics['impressions'] : null;

        return new self(
            impressions: isset($metrics['impressions']) ? (int) $metrics['impressions'] : null,
            //            interactions: isset($metrics['interactions']) ? (int) $metrics['interactions'] : null,
            //            clicks: isset($metrics['clicks']) ? (int) $metrics['clicks'] : null,
            conversions: isset($metrics['conversions']) ? (int) $metrics['conversions'] : null,
            clickThroughRate: isset($metrics['ctr']) ? (float) $metrics['ctr'] : null,
            //            interactionRate: isset($metrics['interactionRate']) ? (float) $metrics['interactionRate'] : null,
            conversionRate: isset($metrics['conversionsFromInteractionsRate']) ? (float) $metrics['conversionsFromInteractionsRate'] : null,
            totalSpendInPennies: (int) round($costMicros / 10000),
            costPerClickInPennies: isset($metrics['averageCpc']) ? (int) round($metrics['averageCpc'] / 10000) : null,
            costPerAcquisitionInPennies: ($conversions > 0)
                ? (int) round(($costMicros / $conversions) / 10000)
                : null,
            costPerMilleInPennies: (is_int($impressions) && $impressions > 0)
                ? (int) round(($costMicros / $impressions) * 1000 / 10000)
                : null,
            returnOnAdSpendInPennies: isset($metrics['conversionsValuePerCost'])
                ? (int) round($metrics['conversionsValuePerCost'] * 100)
                : null,
            currency: $currency,
        );
    }
}
