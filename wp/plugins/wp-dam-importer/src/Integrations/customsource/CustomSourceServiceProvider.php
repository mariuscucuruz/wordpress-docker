<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\CustomSource;

use Illuminate\Support\ServiceProvider;

class CustomSourceServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->registerRoutes();
        $this->publishResources();

        // ServiceProvider::addProviderToBootstrapFile(self::class);
    }

    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/customsource.php', 'customsource');

        // Register the service the package provides.
        $this->app->singleton('customsource', function ($app, $params) {
            return new CustomSource(...$params);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['customsource'];
    }

    public function registerRoutes()
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    /**
     * Console-specific booting.
     */
    protected function publishResources(): void
    {
        // Publishing the configuration file.
        $this->publishes([
            __DIR__ . '/../config/folders.php' => config_path('folders.php'),
        ], 'folders.config');
    }
}
