<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\EventAnalytics\Models;

use MariusCucuruz\DAMImporter\Models\Team;
use MariusCucuruz\DAMImporter\Models\User;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\EventAnalyticFactory;
use MariusCucuruz\DAMImporter\Integrations\EventAnalytics\Enums\EventTypes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MariusCucuruz\DAMImporter\Integrations\EventAnalytics\Enums\EventCategories;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EventAnalytic extends Model
{
    use HasFactory;
    use HasUuids;

    public static function factory($count = null, $state = [])
    {
        $factory = app(EventAnalyticFactory::class);

        return $factory
            ->count(is_numeric($count) ? $count : null)
            ->state(is_callable($count) || is_array($count) ? $count : $state);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class)->withDefault([
            'name' => 'Guest Team',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault([
            'name' => 'Guest User',
        ]);
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'created_at'     => 'datetime:Y-m-d H:i:s',
            'updated_at'     => 'datetime:Y-m-d H:i:s',
            'event_type'     => EventTypes::class,
            'event_category' => EventCategories::class,
        ];
    }
}
