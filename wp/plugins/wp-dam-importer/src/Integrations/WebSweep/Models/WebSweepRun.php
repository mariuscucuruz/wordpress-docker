<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Models;

use Carbon\Carbon;
use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs\Run;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\WebSweepRunFactory;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Enums\WebSweepRunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WebSweepRun extends Model
{
    use HasFactory,
        HasUuids;

    protected $table = 'apify_runs';

    protected static function newFactory(): Factory
    {
        return WebSweepRunFactory::new();
    }

    public static function latestRunStat(): ?WebSweepRunStat
    {
        return self::query()
            ->runStats()
            ->latest()
            ->first();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function crawlItems(): HasMany
    {
        return $this->hasMany(WebSweepCrawlItem::class, 'apify_run_id', 'id');
    }

    public function runStats(): HasMany
    {
        return $this->hasMany(WebSweepRunStat::class, 'apify_run_id', 'id');
    }

    public function updateWithRunData(Run $data, ?string $datasetId = null, ?string $queueId = null): void
    {
        if (! empty($datasetId)) {
            $this->dataset_id = $datasetId;
        }

        if (! empty($queueId)) {
            $this->request_queue_id = $queueId;
        }

        if ($data->data?->finishedAt) {
            $this->finished_at = Carbon::parse($data->data?->finishedAt);
        }

        $this->status = $data->data?->status;
        $this->last_check_at = now();
        $this->total_checks++;

        $stats = $data->data?->stats;

        if (filled($stats)) {
            $this->stats = $stats->toArray();

            WebSweepRunStat::create([
                'apify_run_id'             => $this->id,
                'service_id'               => $this->service_id,
                'memory_current_bytes'     => round($stats->memCurrentBytes ?? 0),
                'memory_average_bytes'     => round($stats->memAvgBytes ?? 0),
                'memory_max_bytes'         => round($stats->memMaxBytes ?? 0),
                'cpu_current_usage'        => round($stats->cpuCurrentUsage ?? 0),
                'cpu_average_usage'        => round($stats->cpuAvgUsage ?? 0),
                'cpu_max_usage'            => round($stats->cpuMaxUsage ?? 0),
                'net_rx_bytes'             => round($stats->netRxBytes ?? 0),
                'net_tx_bytes'             => round($stats->netTxBytes ?? 0),
                'duration_in_milliseconds' => round($stats->durationMillis ?? 0),
                'compute_units'            => round($stats->computeUnits ?? 0, 5),
            ]);
        }

        $this->save();
    }

    protected function casts(): array
    {
        return [
            'id'            => 'string',
            'status'        => WebSweepRunStatus::class,
            'stats'         => 'array',
            'started_at'    => 'datetime',
            'finished_at'   => 'datetime',
            'last_check_at' => 'datetime',
        ];
    }
}
