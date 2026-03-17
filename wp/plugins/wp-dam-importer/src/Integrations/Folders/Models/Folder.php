<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders\Models;

use MariusCucuruz\DAMImporter\Models\User;
use MariusCucuruz\DAMImporter\Traits\Teamable;
use Illuminate\Database\Eloquent\Model;
use MariusCucuruz\DAMImporter\Integrations\Folders\Scopes\FolderScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use MariusCucuruz\DAMImporter\Integrations\Folders\Database\Factories\FolderFactory;
use MariusCucuruz\DAMImporter\Integrations\Folders\Enums\CollectionVisibilityStatus;

#[ScopedBy([FolderScope::class])]
class Folder extends Model
{
    use HasFactory, HasUuids, Teamable;

    public const string DEFAULT = 'default';

    protected static function newFactory(): FolderFactory
    {
        return FolderFactory::new();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')
            ->with('parent');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->with(['children', 'albums']);
    }

    public function childrenWithoutNesting(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function getAncestors(): array
    {
        $ancestors = [];
        $folder = $this;

        while ($folder->parent) {
            $folder = $folder->parent;
            $ancestors[] = $folder;
        }

        return $ancestors;
    }

    public function albums(): BelongsToMany
    {
        return $this->belongsToMany(Album::class)->with('files')->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault([
            'name' => 'Guest User',
        ]);
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime:Y-m-d H:i:s',
            'updated_at' => 'datetime:Y-m-d H:i:s',
            'status'     => CollectionVisibilityStatus::class,
        ];
    }
}
