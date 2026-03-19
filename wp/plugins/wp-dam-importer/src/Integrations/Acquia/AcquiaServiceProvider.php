<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Acquia;

use Illuminate\Support\ServiceProvider;

class AcquiaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->bootForConsole();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/acquia.php', 'acquia');

        $this->app->singleton('acquia', function ($app, $params) {
            return new Acquia(...$params);
        });
    }

    public function provides()
    {
        return ['acquia'];
    }

    protected function bootForConsole(): void
    {
        $this->publishes([
            __DIR__ . '/../config/acquia.php' => config_path('acquia.php'),
        ], 'acquia.config');
    }
}
