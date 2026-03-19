<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AmazonS3;

use Illuminate\Support\ServiceProvider;

class AmazonS3ServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }

        // ServiceProvider::addProviderToBootstrapFile(self::class);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/amazons3.php', 'amazons3');

        $this->app->singleton('amazons3', function ($app, $params) {
            return new AmazonS3(...$params);
        });
    }

    public function provides()
    {
        return ['amazons3'];
    }

    protected function bootForConsole(): void
    {
        $this->publishes([
            __DIR__ . '/../config/amazons3.php' => config_path('amazons3.php'),
        ], 'amazons3.config');

        // $this->commands([]);
    }
}
