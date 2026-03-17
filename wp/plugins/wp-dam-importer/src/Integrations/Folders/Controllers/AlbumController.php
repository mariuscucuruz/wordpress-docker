<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders\Controllers;

use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\Service;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use MariusCucuruz\DAMImporter\Models\SmartCollection;
use MariusCucuruz\DAMImporter\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use MariusCucuruz\DAMImporter\Integrations\Folders\Models\Album;
use MariusCucuruz\DAMImporter\Integrations\Folders\Models\Folder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use MariusCucuruz\DAMImporter\Integrations\Folders\Scopes\AlbumScope;
use MariusCucuruz\DAMImporter\Integrations\Folders\Enums\CollectionVisibilityStatus;

class AlbumController extends Controller
{
    public function show(Request $request)
    {
        $album = Album::withStaticAlbums()->findOrFail($request->route('album'));

        set_likeable_append([Album::class, Service::class]);

        if (request('tab') === 'smart-conditions') {
            $album->load('smartCollection');

            return inertia('Collection/SmartConditions', compact('album'));
        }

        $album->load(['user', 'folders', 'smartCollection']);

        $fileIds = $album->files()->pluck('files.id');

        if ($album->smart_collection_id) {
            $smartCollectionFileIds = $album->smartCollection->files()->pluck('files.id');
            $fileIds = $fileIds->merge($smartCollectionFileIds)->unique();
        }

        $files = File::with([
            'user',
            'sneakpeeks',
            'albums',
            'smartCollections',
            'service:id,name,email,interface_type',
        ])
            ->whereIn('id', $fileIds)
            ->latest()
            ->paginate(config('view.pagination'));

        return inertia('Collection/Show', compact('album', 'files'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Album::class);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (Str::lower((string) $value) === 'favorites') {
                    $fail('This is a reserved collection name.');
                }
            }],
            'files'            => ['nullable', 'array'],
            'files.*'          => ['exists:files,id'],
            'parent_folder_id' => ['nullable', 'exists:folders,id'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $smartCollection = SmartCollection::create([
            'user_id' => auth()->id(),
            'team_id' => $request->user()->currentTeam->id,
        ]);

        $collection = Album::create([
            'user_id'             => $smartCollection->user_id,
            'name'                => $request->input('name'),
            'team_id'             => $smartCollection->team_id,
            'smart_collection_id' => $smartCollection->id,
        ]);

        if (! empty($request['files'])) {
            $collection->files()->attach($request['files']);
        }

        if (! empty($request['parent_folder_id'])) {
            $folder = Folder::findOrFail($request['parent_folder_id']);
            $folder->albums()->save($collection);
        }

        if ($collection->id) {
            flash('Collection created successfully.');

            return back();
        }

        flash('Something went wrong. Please try again.', 'danger');

        return back();
    }

    public function update(Request $request, Album $collection)
    {
        $this->authorize('update', $collection);

        $attributes = $request->validate([
            'name' => 'required|max:255',
        ]);

        if ($collection->update($attributes)) {
            flash('Your Collection name has been updated.');

            return back();
        }

        flash('Something went wrong. Please try again.', 'danger');

        return back();
    }

    public function destroy(Request $request, $collectionId)
    {
        $collection = Album::withoutGlobalScope(AlbumScope::class)->findOrFail($collectionId);

        $this->authorize('delete', $collection);

        $request->validate([
            'deletePermanently' => 'required|boolean',
        ]);

        $deletePermanently = $request->deletePermanently;

        rescue(function () use ($deletePermanently, $collection) {
            if ($deletePermanently) {
                $collection->delete();
                flash('Collection was deleted successfully.');
            } else {
                $collection->update(['status' => CollectionVisibilityStatus::ARCHIVED]);
                flash('Collection was archived successfully.');
            }
        }, function () {
            flash('Unable to delete collection.', 'danger');
        });

        return back();
    }

    public function restore(int|string $collectionId)
    {
        $collection = Album::withoutGlobalScope(AlbumScope::class)->findOrFail($collectionId);

        $this->authorize('delete', $collection);

        rescue(function () use ($collection) {
            $collection->update(['status' => CollectionVisibilityStatus::SHARED]);
            flash('Collection has been successfully restored.');
        }, function () {
            flash('Unable to restore the collection', 'danger');
        });

        return back();
    }

    public function restoreAll(Request $request)
    {
        $this->authorize('delete', [$request->user(), Album::class]);

        rescue(function () {
            $collections = Album::where('status', CollectionVisibilityStatus::ARCHIVED);
            $collections->update(['status' => CollectionVisibilityStatus::SHARED]);

            flash('All collections have been successfully restored.');
        }, function () {
            flash('Unable to restore all collections', 'danger');
        });

        return back();
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        $this->authorize('delete', [$request->user(), Album::class]);

        rescue(function () {
            Album::query()
                ->where('status', CollectionVisibilityStatus::ARCHIVED)
                ->delete();

            flash('All collections have been successfully deleted.');
        }, function () {
            flash('Unable to delete all collections', 'danger');
        });

        return back();
    }

    public function removeFromAlbum(Request $request)
    {
        $validated = $request->validate([
            'fileIds'   => 'required|array|min:1',
            'fileIds.*' => 'uuid|exists:files,id',
            'albumId'   => 'required|uuid|exists:albums,id',
        ]);

        $album = Album::withStaticAlbums()->findOrFail($validated['albumId']);
        $this->authorize('removeFromAlbum', $album);

        try {
            $this->decrementAssetsCount($album, $validated['fileIds']);
            $album->files()->toggle($validated['fileIds']);
            reindex_searchable_files(data_get($validated, 'fileIds'));
            flash('Files removed from album');
        } catch (QueryException $e) {
            logger()->error($e->getMessage());
            flash('Could not remove files from collection', 'danger');
        }

        return back();
    }

    public function addFilesToCollection(Request $request)
    {
        $validated = $request->validate([
            'fileIds'         => 'required|array|min:1',
            'fileIds.*'       => 'uuid|exists:files,id',
            'collectionIds'   => 'required|array|min:1',
            'collectionIds.*' => 'uuid|exists:albums,id',
        ]);

        foreach ($validated['collectionIds'] as $collectionId) {
            $collection = Album::withStaticAlbums()->findOrFail($collectionId);

            $this->authorize('addToCollection', $collection);

            $collection->touch();

            try {
                $this->incrementAssetsCount($collection, $validated['fileIds']);
                $collection->files()->syncWithoutDetaching($validated['fileIds']);
                reindex_searchable_files(data_get($validated, 'fileIds'));
            } catch (QueryException $e) {
                logger()->error($e->getMessage());
                flash('Could not add files to collection', 'danger');
            }
        }

        $collection = count($validated['collectionIds']) === 1 ? 'Collection' : 'Collections';
        $asset = count($validated['fileIds']) === 1 ? 'Asset' : 'Assets';

        flash("{$asset} added to {$collection}");

        return back();
    }

    public function toggleFavorites(Request $request)
    {
        set_likeable_append();

        $validated = $request->validate([
            'fileIds'   => 'required|array|min:1',
            'fileIds.*' => 'uuid|exists:files,id',
            'action'    => 'required|string|in:add,remove',
        ]);

        $album = Album::withStaticAlbums()->where([
            'type' => 'static',
            'name' => 'favorites',
        ])->firstOrFail();

        $this->authorize('toggleFavorites', $album);

        $album?->touch();

        try {
            if ($validated['action'] === 'remove') {
                $this->decrementAssetsCount($album, $validated['fileIds']);
                $album->files()->detach($validated['fileIds']);
            }

            if ($validated['action'] === 'add') {
                $this->incrementAssetsCount($album, $validated['fileIds']);
                $album->files()->syncWithoutDetaching($validated['fileIds']);
            }

            reindex_searchable_files(data_get($validated, 'fileIds'));
        } catch (QueryException $e) {
            logger()->error($e->getMessage());
            flash('Could not add to Favorites', 'danger');
        }

        $message = $validated['action'] === 'add' ? 'Added to Favorites' : 'Removed from Favorites';

        flash($message);

        return back();
    }

    public function toggleLike(Album $album)
    {
        $album->toggleLike();

        flash(
            $album->isLiked()
                ? 'Collection added to Favorites.'
                : 'Collection removed from Favorites.'
        );

        return back();
    }

    public function toggleFolder(Request $request)
    {
        $validated = $request->validate([
            'collectionIds' => ['required', 'array', 'exists:albums,id'],
            'folderIds'     => ['required', 'array', 'exists:folders,id'],
            'action'        => ['required', 'string', 'in:add,remove'],
        ]);

        $collectionIds = $validated['collectionIds'];
        $folderIds = $validated['folderIds'];
        $action = $validated['action'];

        foreach ($folderIds as $folderId) {
            $folder = Folder::findOrFail($folderId);

            try {
                if ($action === 'add') {
                    $folder->albums()->syncWithoutDetaching($collectionIds);
                }

                if ($action === 'remove') {
                    $folder->albums()->detach($collectionIds);
                }
            } catch (QueryException $e) {
                logger()->error($e->getMessage());
                flash('Could not add to Folder', 'danger');
            }
        }

        $isSingle = count($collectionIds) === 1;
        $message = $isSingle ? 'Collection' : 'Collections';

        if ($action === 'add') {
            flash($message . ' added to Folder.');
        } else {
            flash($message . ' removed from Folder.');
        }

        return back();
    }

    private function incrementAssetsCount(Album $album, array $fileIds): void
    {
        $existingFileIds = $album->files()->pluck('files.id')->toArray();
        $newFilesCount = count(array_diff($fileIds, $existingFileIds));

        if ($newFilesCount > 0) {
            $album->increment('assets_count', $newFilesCount);
        }
    }

    private function decrementAssetsCount(Album $album, array $fileIds): void
    {
        $existingFileIds = $album->files()->pluck('files.id')->toArray();
        $filesToRemove = array_intersect($fileIds, $existingFileIds);
        $removedCount = count($filesToRemove);

        if ($removedCount > 0) {
            $album->decrement('assets_count', $removedCount);
        }
    }
}
