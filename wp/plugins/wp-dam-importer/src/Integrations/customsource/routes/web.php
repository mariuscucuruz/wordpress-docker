<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MariusCucuruz\DAMImporter\Integrations\CustomSource\Controllers\CustomSourceController;

Route::post('/api/store-file', [CustomSourceController::class, 'storeFile'])->name('storeFile');
Route::post('/api/store-file/callback', [CustomSourceController::class, 'storeFileCallBack'])->name('storeFileCallBack');

Route::middleware(['web', 'auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    //        TODO: Add ability for user to input custom source details
    Route::get('service/create-custom', [CustomSourceController::class, 'createService'])->name('customSource.createService');
    // Route::get('/api/list-files', [CustomSourceController::class, 'listFiles'])->name('listFiles');
});
