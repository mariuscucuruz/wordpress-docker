<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Models\Meta;
use MariusCucuruz\DAMImporter\Models\Setting;
use MariusCucuruz\DAMImporter\Support\IntegrationSupport;
use Illuminate\Support\Facades\Route;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

// NOTE: DO NOT WRAP THIS ROUTE WITH auth:sanctum MIDDLEWARE!
// A Service means either 'Storage' or 'Source' or 'Destination' or 'Function'.
Route::get('/{package}-redirect', function ($package) {
    // GET THE STATE & PARSE IT:
    $requestState = request('state');
    $state = null;

    if (is_string($requestState)) {
        $state = json_decode($requestState, true);
    } elseif (is_array($requestState)) {
        $state = $requestState;
    }

    if (! is_array($state)) {
        $state = [];
    }

    // GET THE SETTING:
    $settings = Setting::findMany(data_get($state, 'settings'));

    // HANDLE ANY META SETTINGS:
    Meta::applySettings($state, $settings);

    // GET THE SERVICE INSTANCE:
    /** @var SourceIntegration $serviceInstance */
    $serviceInstance = app($package, compact('settings'));

    // GET THE TOKENS:
    try {
        $service = $serviceInstance->getTokens()->toArray();
    } catch (CouldNotGetToken $e) {
        logger()->error($e->getMessage(), [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        flash($e->getMessage(), 'error');

        return to_route('catalogue.index', ['error' => "Unable to get token for {$package}"]);
    }

    // GET SERVICE IDENTIFIER UNIQUE TO REMOTE USER AND DOMAIN
    $remoteServiceId = $serviceInstance->hasRemoteServiceId() && filled($serviceInstance->getRemoteServiceId())
        ? md5($serviceInstance->getRemoteServiceId())
        : null;

    // GET THE USER:
    try {
        $user = $serviceInstance->getUser()->toArray();
    } catch (Exception $e) {
        logger()->error("[{$package}] User info failed: {$e->getMessage()}", $e->getTrace());

        return to_route('exception.credentials', compact('package', 'service'));
    }
    // GET THE INTERFACE TYPE:
    $interfaceType = $serviceInstance->getInterfaceType();

    // REDIRECT TO THE SERVICE CREATION ROUTE:
    return to_route('service.create', [
        ...compact('service', 'user', 'interfaceType', 'remoteServiceId'),
        'settings' => collect($settings)->pluck('id')->toArray(), // MUST PASS ARRAY iDs TO THE ROUTE (SOME HAVE LIMITS SO BE CAREFUL)
        'meta'     => data_get($state, 'meta'),
    ]);
});

Route::post('/{package}-test', function ($package) {
    $package = str()->slug($package, '');
    $settings = Setting::findMany(request()->settings ?? null);
    $service = app($package, compact('settings'));

    try {
        // PACKAGE MUST IMPLEMENT THE 'IsTestable' INTERFACE:
        $isValid = $service->testSettings($settings);

        abort_unless($isValid, 422, 'Failed to validate credentials');

        return response(['message' => 'Credentials look valid'], 200);
    } catch (HttpException|Exception $e) {
        return response(['message' => $e->getMessage()], 500);
    }
});

Route::get('exception/{package}/credentials', function (string $package) {
    return inertia('Error/CredentialsError', [
        'platform' => IntegrationSupport::all(['source'])[$package],
    ]);
})->name('exception.credentials');
