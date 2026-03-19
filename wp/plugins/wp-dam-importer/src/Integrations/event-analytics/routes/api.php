<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MariusCucuruz\DAMImporter\Integrations\EventAnalytics\EventAnalytics;

Route::middleware(['api'])
    ->post('ea/collect', [EventAnalytics::class, 'collectAnalytics'])
    ->name('api.collect');
