<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Models;

use MariusCucuruz\DAMImporter\DTOs\DateRange;
use MariusCucuruz\DAMImporter\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\WebSweepRunStatFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WebSweepRunStat extends Model
{
    use HasFactory,
        HasUuids;

    protected $table = 'apify_run_stats';

    protected static function newFactory(): Factory
    {
        return WebSweepRunStatFactory::new();
    }

    public static function totalComputeUnitsForDateRange(DateRange $range): float
    {
        $result = self::query()
            ->select('apify_run_id', 'compute_units')
            ->whereBetween('created_at', [$range->start, $range->end ?? now()])
            ->whereIn('created_at', function ($query) {
                $query->selectRaw('MAX(created_at)')
                    ->from('apify_run_stats')
                    ->groupBy('apify_run_id');
            })
            ->sum('compute_units');

        return round((float) $result, 8);
    }

    public function apifyRun(): BelongsTo
    {
        return $this->belongsTo(WebSweepRun::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
