<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MariusCucuruz\DAMImporter\Integrations\Folders\Controllers\AlbumController;
use MariusCucuruz\DAMImporter\Integrations\Folders\Controllers\FolderController;
use MariusCucuruz\DAMImporter\Integrations\Folders\Controllers\FolderAlbumController;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;

Route::middleware(['web', 'auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::prefix('folder')->name('folder.')
        ->controller(FolderController::class)->group(function () {
            Route::post('store', 'store')->name('store')->middleware([HandlePrecognitiveRequests::class]);
            Route::post('move', 'moveAlbumToFolder')->name('move');
            Route::post('movefolder', 'moveFolderToFolder')->name('movefolder');
            Route::post('remove', 'removeAlbumFromFolder')->name('remove');
            Route::patch('{folder}', 'update')->name('update')->middleware([HandlePrecognitiveRequests::class]);
            Route::delete('{folder}', 'destroy')->name('destroy')->middleware([HandlePrecognitiveRequests::class]);
        });

    Route::prefix('collection')->name('collection.')
        ->controller(AlbumController::class)->group(function () {
            Route::get('show/{album}', 'show')->name('show');
            Route::post('store', 'store')->name('store')->middleware([HandlePrecognitiveRequests::class]);
            Route::post('addfiles', 'addFilesToCollection')->name('addfiles');
            Route::post('remove', 'removeFromAlbum')->name('remove');
            Route::post('favorite', 'toggleFavorites')->name('favorite');
            Route::post('toggle-like/{album}', 'toggleLike')->name('toggle.like');
            Route::post('toggle-folder', 'toggleFolder')->name('toggle.folder');
            Route::put('restore-all', 'restoreAll')->name('restore.all');
            Route::put('{collection}', 'restore')->name('restore');
            Route::patch('{collection}', 'update')->name('update')->middleware([HandlePrecognitiveRequests::class]);
            Route::delete('destroy-all', 'destroyAll')->name('destroy.all');
            Route::delete('{collection}', 'destroy')->name('destroy')->middleware([HandlePrecognitiveRequests::class]);
        });

    Route::prefix('collections')->name('collections.')
        ->controller(FolderAlbumController::class)->group(function () {
            Route::get('folders', 'getFolders')->name('folders');
            Route::get('collections', 'getCollections')->name('collections');
            Route::get('{folder?}', 'index')->name('index');
        });
});
