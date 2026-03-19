<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders\Models;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\User;
use MariusCucuruz\DAMImporter\Traits\Likeable;
use MariusCucuruz\DAMImporter\Traits\Teamable;
use MariusCucuruz\DAMImporter\Models\SmartCollection;
use MariusCucuruz\DAMImporter\Models\Scopes\TeamScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use MariusCucuruz\DAMImporter\Integrations\Folders\Scopes\AlbumScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use MariusCucuruz\DAMImporter\Integrations\Folders\Database\Factories\AlbumFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use MariusCucuruz\DAMImporter\Integrations\Folders\Enums\CollectionVisibilityStatus;

#[ScopedBy([AlbumScope::class])]
class Album extends Model
{
    use HasFactory;
    use HasUuids;
    use Likeable;
    use SoftDeletes;
    use Teamable;

    public const string STATIC = 'static';

    protected static function newFactory(): AlbumFactory
    {
        return AlbumFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault([
            'name' => 'Guest Author',
        ]);
    }

    public function folders(): BelongsToMany
    {
        return $this->belongsToMany(Folder::class)->withTimestamps();
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class);
    }

    public function smartCollection(): BelongsTo
    {
        return $this->belongsTo(SmartCollection::class);
    }

    public function scopeWithStaticAlbums(Builder $query): Builder
    {
        return $query
            ->withoutGlobalScope(TeamScope::class)
            ->where(function (Builder $query) {
                $query
                    ->where('albums.type', 'static')
                    ->orWhere('albums.team_id', auth()->user()?->currentTeam?->id);
            });
    }

    public function scopeForFolder(Builder $query, ?Folder $folder): Builder
    {
        return $query->when(
            data_get($folder, 'id'),
            fn (Builder $q) => $q->whereHas('folders', fn ($q) => $q->where('folder_id', $folder->id)),
            fn (Builder $q) => $q->whereNull('folder_id')
        );
    }

    public function scopeShared(Builder $query): Builder
    {
        return $query->where('albums.status', CollectionVisibilityStatus::SHARED);
    }

    public function scopeSearchByName(Builder $query, ?string $search): Builder
    {
        return $query->when(
            $search,
            fn (Builder $q) => $q->where('albums.name', 'ILIKE', "%{$search}%")
        );
    }

    public function scopeByOwners(Builder $query, ?string $owners): Builder
    {
        return $query->when($owners, function (Builder $q) use ($owners) {
            $ownerIds = explode(',', $owners);
            $q->whereIn('albums.user_id', $ownerIds);
        });
    }

    public function scopeWithTotalAssetsCount(Builder $query): Builder
    {
        return $query
            ->select('albums.*')
            ->selectRaw($this->getTotalAssetsCountSql() . ' as total_assets_count');
    }

    public function scopeFilterByAssetsCountRange(Builder $query, ?int $min, ?int $max): Builder
    {
        return $query->when(
            $min !== null && $max !== null,
            fn (Builder $q) => $q->whereBetween(DB::raw($this->getTotalAssetsCountSql()), [$min, $max])
        );
    }

    public function scopeOrderByStaticFirst(Builder $query): Builder
    {
        return $query->orderByRaw("CASE WHEN albums.type = 'static' THEN 0 ELSE 1 END ASC");
    }

    public function getTotalAssetsCountSql(): string
    {
        return 'COALESCE(albums.assets_count, 0) + COALESCE((select sc.assets_count from smart_collections sc where sc.id = albums.smart_collection_id), 0)';
    }

    protected function casts(): array
    {
        return [
            'created_at'   => 'datetime:Y-m-d H:i:s',
            'updated_at'   => 'datetime:Y-m-d H:i:s',
            'status'       => CollectionVisibilityStatus::class,
            'assets_count' => 'int',
        ];
    }
}
