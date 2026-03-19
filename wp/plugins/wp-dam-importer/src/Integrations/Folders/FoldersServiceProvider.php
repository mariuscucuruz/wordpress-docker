<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Folders;

use Illuminate\Support\ServiceProvider;

class FoldersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->registerRoutes();
        $this->publishResources();
        // ServiceProvider::addProviderToBootstrapFile(self::class);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/folders.php', 'folders');

        $this->app->singleton('folders', function ($app, $params) {
            return new Folders;
        });
    }

    public function provides()
    {
        return ['folders'];
    }

    public function registerRoutes()
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    protected function publishResources(): void
    {
        $this->publishes([
            __DIR__ . '/../config/folders.php' => config_path('folders.php'),
        ], 'folders.config');
    }
}
