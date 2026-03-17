<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MariusCucuruz\DAMImporter\Http\Controllers\Controller;
use MariusCucuruz\DAMImporter\Integrations\Folders\Models\Album;
use MariusCucuruz\DAMImporter\Integrations\Folders\Models\Folder;
use Illuminate\Database\Eloquent\Builder;
use MariusCucuruz\DAMImporter\Integrations\Folders\Resources\AlbumSimpleResource;
use MariusCucuruz\DAMImporter\Integrations\Folders\Resources\FolderSimpleResource;
use MariusCucuruz\DAMImporter\Integrations\Folders\Enums\CollectionVisibilityStatus;

class FolderAlbumController extends Controller
{
    public function index(Request $request, Folder $folder)
    {
        $validated = $request->validate([
            'sort'             => 'nullable|string',
            'search'           => 'nullable|string',
            'owners'           => 'nullable|string',
            'assets_count_max' => 'nullable|numeric|min:0',
            'assets_count_min' => 'nullable|numeric|min:0',
        ]);

        set_likeable_append([Album::class]);

        $thumbnailLimit = (int) config('smart-collections.number_of_preview_thumbnails_displayed');

        return inertia('CollectionsAndFolders/Index', [
            'ancestors'   => $this->buildAncestors($folder),
            'folders'     => $this->buildFoldersQuery($folder, $validated),
            'owners'      => $this->getAlbumOwners(),
            'assetsCount' => $this->getAssetsCountRange(),
            'albums'      => Inertia::scroll($this->getAlbums($folder, $validated, $thumbnailLimit)),
        ]);
    }

    public function getFolders(): array
    {
        $folders = Folder::query()
            ->where('status', CollectionVisibilityStatus::SHARED)
            ->get();

        return FolderSimpleResource::collection($folders)->all();
    }

    public function getCollections(): array
    {
        $albums = Album::query()
            ->withStaticAlbums()
            ->where('status', CollectionVisibilityStatus::SHARED)
            ->get();

        return AlbumSimpleResource::collection($albums)->all();
    }

    private function buildAncestors(Folder $folder): array
    {
        $ancestors = $folder->getAncestors();

        return collect($ancestors)
            ->map(fn ($ancestor) => [
                'id'   => $ancestor->id,
                'name' => $ancestor->name,
            ])
            ->reverse()
            ->values()
            ->push([
                'id'   => $folder->id,
                'name' => $folder->name,
            ])
            ->all();
    }

    private function buildFoldersQuery(Folder $folder, array $validated)
    {
        return Folder::query()
            ->when(
                data_get($folder, 'id'),
                fn (Builder $query) => $query->where('parent_id', $folder->id),
                fn (Builder $q)     => $q->whereNull('parent_id')
            )
            ->with([
                'user:id,name,email,profile_photo_path',
                'childrenWithoutNesting:id,parent_id,name,status',
                'albums:id,name',
            ])
            ->withCount(['albums', 'childrenWithoutNesting as children_count'])
            ->when(
                data_get($validated, 'search'),
                fn ($q, $filter) => $q->where('name', 'ILIKE', "%{$filter}%")
            )
            ->when(
                data_get($validated, 'sort'),
                fn ($query) => $this->applySorting($query, $validated['sort']),
                fn ($q)     => $q->latest()
            )
            ->get();
    }

    private function getAlbumOwners()
    {
        return Album::query()
            ->select('user_id')
            ->distinct()
            ->with('user:id,name,email,profile_photo_path')
            ->get()
            ->map(fn ($album) => $album->user);
    }

    private function getAssetsCountRange(): object
    {
        return DB::table('albums')
            ->leftJoin(
                'smart_collections',
                'albums.smart_collection_id',
                '=',
                'smart_collections.id'
            )
            ->where('albums.team_id', auth()->user()->currentTeam->id)
            ->where('albums.status', CollectionVisibilityStatus::SHARED)
            ->selectRaw('
                COALESCE(MIN(albums.assets_count + COALESCE(smart_collections.assets_count, 0)), 0) AS min,
                COALESCE(MAX(albums.assets_count + COALESCE(smart_collections.assets_count, 0)), 0) AS max
            ')
            ->first();
    }

    private function getAlbums(Folder $folder, array $validated, int $thumbnailLimit)
    {
        $albums = Album::query()
            ->withStaticAlbums()
            ->forFolder($folder)
            ->shared()
            ->searchByName(data_get($validated, 'search'))
            ->byOwners(data_get($validated, 'owners'))
            ->with([
                'user:id,name,email,profile_photo_path',
                'smartCollection:id,assets_count',
            ])
            ->withExists([
                'likes as is_liked' => fn ($q) => $q->where('user_id', auth()->id()),
            ])
            ->orderByStaticFirst()
            ->when(
                data_get($validated, 'sort'),
                fn ($query) => $this->applySorting($query, $validated['sort']),
                fn ($q)     => $q->latest('albums.created_at')->orderBy('albums.id')
            )
            ->withTotalAssetsCount()
            ->filterByAssetsCountRange(
                data_get($validated, 'assets_count_min') !== null ? (int) $validated['assets_count_min'] : null,
                data_get($validated, 'assets_count_max') !== null ? (int) $validated['assets_count_max'] : null
            )
            ->with($this->getThumbnailRelations($thumbnailLimit))
            ->paginate(config('view.collections_pagination'));

        $this->loadThumbnails($albums, $thumbnailLimit);

        return $albums;
    }

    private function getThumbnailRelations(int $thumbnailLimit): array
    {
        return [
            'files' => fn ($q) => $q
                ->select(['files.id', 'files.thumbnail'])
                ->whereNotNull('thumbnail')
                ->orderByDesc('files.created_at')
                ->orderByDesc('files.id')
                ->limit($thumbnailLimit),

            'smartCollection.files' => fn ($q) => $q
                ->select(['files.id', 'files.thumbnail'])
                ->whereNotNull('thumbnail')
                ->orderByDesc('files.created_at')
                ->orderByDesc('files.id')
                ->limit($thumbnailLimit),
        ];
    }

    private function loadThumbnails($albums, int $thumbnailLimit): void
    {
        foreach ($albums->items() as $album) {
            $album->thumbnails = $album->files
                ->merge($album->smartCollection?->files ?? collect())
                ->unique('id')
                ->pluck('thumbnail')
                ->filter()
                ->take($thumbnailLimit)
                ->values()
                ->all();
        }
    }

    private function applySorting(Builder $query, string $sort): Builder
    {
        $allowed = ['created_at'];
        [$column, $direction] = explode(',', $sort);

        if (in_array($column, $allowed, true)) {
            $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
            $query->orderBy($column, $direction);
        }

        return $query;
    }
}
