<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Integrations\Nataero\Nataero;
use Illuminate\Support\Facades\Route;

Route::post('/api/nataero/callback', [Nataero::class, 'processCallback'])
    ->name('nataero.processCallback');

Route::middleware(['web', 'auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::get('nataero/file', [Nataero::class, 'inputDetails'])->name('nataero.inputDetails');
});
