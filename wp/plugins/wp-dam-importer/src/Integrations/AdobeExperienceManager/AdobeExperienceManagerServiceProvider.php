<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AdobeExperienceManager;

use Illuminate\Support\ServiceProvider;

class AdobeExperienceManagerServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     */
    public function boot(): void
    {
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'clickonmedia');
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'clickonmedia');
        // $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

        $this->bootForConsole();
    }

    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/adobeexperiencemanager.php', 'adobeexperiencemanager');

        // Register the service the package provides.
        $this->app->singleton('adobeexperiencemanager', function ($app, $params) {
            return new AdobeExperienceManager(...$params);
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['adobeexperiencemanager'];
    }

    /**
     * Console-specific booting.
     */
    protected function bootForConsole(): void
    {
        // Publishing the configuration file.
        $this->publishes([
            __DIR__ . '/../config/adobeexperiencemanager.php' => config_path('adobeexperiencemanager.php'),
        ], 'adobeexperiencemanager.config');

        // Publishing the views.
        /*$this->publishes([
            __DIR__.'/../resources/views' => base_path('resources/views/vendor/clickonmedia'),
        ], 'adobeexperiencemanager.views');*/

        // Publishing assets.
        /*$this->publishes([
            __DIR__.'/../resources/assets' => public_path('vendor/clickonmedia'),
        ], 'adobeexperiencemanager.assets');*/

        // Publishing the translation files.
        /*$this->publishes([
            __DIR__.'/../resources/lang' => resource_path('lang/vendor/clickonmedia'),
        ], 'adobeexperiencemanager.lang');*/

        // Registering package commands.
        // $this->commands([]);
    }
}
