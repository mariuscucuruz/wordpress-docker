<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Metaads\DTOs;

use MariusCucuruz\DAMImporter\Enums\Currency;
use Illuminate\Support\Carbon;

class MetaAdMetricsDTO
{
    //  https://developers.facebook.com/docs/marketing-api/reference/adgroup/insights
    //  https://developers.facebook.com/docs/marketing-api/reference/ads-action-stats/
    public function __construct(
        public ?int $reach = null,
        public ?int $impressions = null,
        public ?int $views = null,
        public ?int $videoDepth = null,
        public ?int $averageWatchTimeInMs = null,
        public ?int $engagements = null,
        public ?int $shares = null,
        public ?int $conversions = null,
        public ?int $comments = null,
        public ?int $totalSpendInPennies = null,
        public ?Currency $currency = Currency::UNKNOWN,
        public ?float $clickThroughRate = null,
        public ?int $costPerClickInPennies = null,
        public ?int $costPerMilleInPennies = null,
        public ?int $costPerAcquisitionInPennies = null,
        public ?float $conversionRate = null,
        public ?float $engagementRate = null,
        public ?int $returnOnAdSpendInPennies = null,
        public ?float $frequency = null,
        public ?Carbon $adStartTime = null,
        public ?Carbon $adEndTime = null,
    ) {}

    public static function fromApi(array $metrics): self
    {
        $reach = data_get($metrics, 'reach');
        $impressions = data_get($metrics, 'impressions');
        $actions = collect(data_get($metrics, 'actions') ?? []);
        $videoDepth = data_get($metrics, 'video_p25_watched_actions.0.value');

        $views = $actions->firstWhere('action_type', 'video_view')['value'] ?? null;
        $postEngagements = $actions->firstWhere('action_type', 'post_engagement')['value'] ?? null;
        $shares = $actions->firstWhere('action_type', 'post')['value'] ?? null;
        $conversions = $actions->firstWhere('action_type', 'link_click')['value'] ?? null;
        $comments = $actions->firstWhere('action_type', 'comment')['value'] ?? null;

        $spend = data_get($metrics, 'spend');
        $totalSpendInPennies = is_numeric($spend) ? (int) ($spend * 100) : null;

        $averageWatchTimeRaw = data_get($metrics, 'video_avg_time_watched_actions.0.value');
        $averageWatchTimeInMs = is_numeric($averageWatchTimeRaw)
            ? (int) ($averageWatchTimeRaw * 1000)
            : null;

        return new self(
            reach: filled($reach) ? (int) $reach : null,
            impressions: filled($impressions) ? (int) $impressions : null,
            views: filled($views) ? (int) $views : null,
            videoDepth: filled($videoDepth) ? (int) $videoDepth : null,
            averageWatchTimeInMs: $averageWatchTimeInMs,
            engagements: filled($postEngagements) ? (int) $postEngagements : null,
            shares: filled($shares) ? (int) $shares : null,
            conversions: filled($conversions) ? (int) $conversions : null,
            comments: filled($comments) ? (int) $comments : null,
            totalSpendInPennies: $totalSpendInPennies,
            currency: Currency::tryFrom(data_get($metrics, 'account_currency')) ?? Currency::UNKNOWN,
            clickThroughRate: filled(data_get($metrics, 'ctr')) ? (float) data_get($metrics, 'ctr') : null,
            costPerClickInPennies: filled(data_get($metrics, 'cpc')) ? (int) (data_get($metrics, 'cpc') * 100) : null,
            costPerMilleInPennies: filled(data_get($metrics, 'cpm')) ? (int) (data_get($metrics, 'cpm') * 100) : null,
            costPerAcquisitionInPennies: filled(data_get($metrics, 'cpa')) ? (int) (data_get($metrics, 'cpa') * 100) : null, // Not present
            conversionRate: filled(data_get($metrics, 'conversion_rate')) ? (float) data_get($metrics, 'conversion_rate') : null, // Not present
            engagementRate: filled(data_get($metrics, 'engagement_rate')) ? (float) data_get($metrics, 'engagement_rate') : null, // Not present
            returnOnAdSpendInPennies: filled(data_get($metrics, 'purchase_roas.0.value')) ? (int) (data_get($metrics, 'purchase_roas.0.value') * 100) : null,
            frequency: filled(data_get($metrics, 'frequency')) ? (float) data_get($metrics, 'frequency') : null,
            adStartTime: isset($metrics['date_start']) ? Carbon::parse($metrics['date_start']) : null,
            adEndTime: isset($metrics['date_stop']) ? Carbon::parse($metrics['date_stop']) : null,
        );
    }
}
