<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Models;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\WebSweepCrawlItemFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WebSweepCrawlItem extends Model
{
    use HasFactory,
        HasUuids;

    protected $table = 'apify_crawl_items';

    protected $casts = [
        'imported_at'   => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
        'should_import' => 'boolean',
    ];

    protected static function newFactory(): Factory
    {
        return WebSweepCrawlItemFactory::new();
    }

    public function file(): HasOne
    {
        return $this->hasOne(
            File::class,
            'remote_service_file_id',
            'id'
        )->whereColumn('files.service_id', 'apify_crawl_items.service_id');
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
