<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders\Controllers;

use Illuminate\Http\Request;
use MariusCucuruz\DAMImporter\Http\Controllers\Controller;
use MariusCucuruz\DAMImporter\Integrations\Folders\Models\Album;
use MariusCucuruz\DAMImporter\Integrations\Folders\Models\Folder;
use Illuminate\Database\QueryException;
use MariusCucuruz\DAMImporter\Integrations\Folders\Scopes\FolderScope;

class FolderController extends Controller
{
    public function store(Request $request)
    {
        $this->authorize('create', Folder::class);

        $request->validate([
            'name'             => 'required|string|max:255',
            'files'            => 'nullable|array',
            'files.*'          => 'exists:files,id',
            'parent_folder_id' => 'nullable|exists:folders,id',
            'album_id'         => 'nullable|exists:albums,id',
        ]);

        $folder = Folder::create([
            'user_id' => auth()->id(),
            'name'    => $request['name'],
        ]);

        if (! empty($request['files'])) {
            $collection = Album::create([
                'user_id' => auth()->id(),
                'name'    => 'Untitled',
            ]);

            $fileIds = $request['files'];
            $collection->files()->attach($fileIds);

            $folder->albums()->save($collection);
        }

        if (! empty($request['collectionIds'])) {
            $collectionIds = $request['collectionIds'];

            try {
                $folder->albums()->attach($collectionIds);
            } catch (QueryException $e) {
                logger()->error($e->getMessage());
            }
        }

        if (! empty($request['parent_folder_id'])) {
            Folder::findOrFail($request['parent_folder_id'])->children()->save($folder);
        }

        if ($folder->id) {
            flash('Folder created successfully.');
        } else {
            flash('Something went wrong. Please try again.', 'danger');
        }

        return back();
    }

    public function update(Request $request, Folder $folder)
    {
        $this->authorize('update', $folder);

        $attributes = $request->validate([
            'name' => 'required|max:255',
        ]);

        if ($folder->update($attributes)) {
            flash('Your Folder name has been updated.');
        } else {
            flash('Something went wrong. Please try again.', 'danger');
        }

        return back();
    }

    public function destroy($folderId)
    {
        $folder = Folder::withoutGlobalScope(FolderScope::class)->findOrFail($folderId);

        $this->authorize('delete', $folder);

        // Update albums to not have a parent folder
        $folder->albums()->detach();

        $folder->children()->delete();

        if ($folder->delete()) {
            flash('Folder was deleted successfully.');
        } else {
            flash('Could not delete the folder.', 'danger');
        }

        return back();
    }

    public function moveAlbumToFolder(Request $request)
    {
        $attrs = $request->validate([
            'albumId'  => ['required', 'integer'],
            'folderId' => ['required', 'integer'],
        ]);

        try {
            Album::findOrFail($attrs['albumId'])->update(['folder_id' => $attrs['folderId']]);
            flash('Move Album to Folder successfully.');

            return back();
        } catch (QueryException $e) {
            logger()->error($e->getMessage());
            flash('Could move Album to Folder.', 'danger');

            return back();
        }
    }

    public function removeAlbumFromFolder(Request $request)
    {
        $attributes = $request->validate([
            'albumId'  => ['required', 'integer'],
            'folderId' => ['required', 'integer'],
        ]);

        $albumId = (int) $attributes['albumId'];

        try {
            Album::findOrFail($albumId)->update(['folder_id' => null]);
            flash('Removed Album from Folder successfully.');

            return back();
        } catch (QueryException $e) {
            logger()->error($e->getMessage());
            flash('Could remove Album from Folder.', 'danger');

            return back();
        }
    }

    public function moveFolderToFolder(Request $request)
    {
        $attributes = $request->validate([
            'folderIds'      => ['required', 'array', 'exists:folders,id'],
            'targetFolderId' => ['required', 'string', 'exists:folders,id'],
        ]);

        $folderIds = $attributes['folderIds'];
        $targetFolderId = $attributes['targetFolderId'];

        foreach ($folderIds as $folderId) {
            try {
                Folder::findOrFail($folderId)->update(['parent_id' => $targetFolderId]);
            } catch (QueryException $e) {
                logger()->error($e->getMessage());
                flash('Could move Folder to Folder.', 'danger');

                return back();
            }
        }

        $isSingle = count($folderIds) === 1;
        $message = $isSingle ? 'Folder' : 'Folders';

        flash($message . ' moved successfully.');

        return back();
    }
}
