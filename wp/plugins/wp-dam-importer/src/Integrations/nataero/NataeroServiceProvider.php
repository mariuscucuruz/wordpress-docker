<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero;

use Illuminate\Support\ServiceProvider;
use Spatie\WebhookClient\WebhookClientServiceProvider;

class NataeroServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->registerRoutes();

        $this->publishResources();

        // ServiceProvider::addProviderToBootstrapFile(self::class);
    }

    public function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
    }

    public function register(): void
    {
        $this->app->register(WebhookClientServiceProvider::class);

        $this->mergeConfigFrom(__DIR__ . '/../config/nataero.php', 'nataero');

        $this->app->singleton('nataero', fn () => new Nataero);
    }

    public function provides(): array
    {
        return ['nataero'];
    }

    protected function publishResources(): void
    {
        $this->publishes([
            __DIR__ . '/../config/nataero.php' => config_path('nataero.php'),
        ], 'nataero.config');

        $this->commands([
            Commands\MediaNataeroForgetCommand::class,
            Commands\NataeroDispatchConversions::class,
            Commands\NataeroDispatchMediainfo::class,
            Commands\NataeroDispatchExif::class,
            Commands\NataeroDispatchHyper1::class,
            Commands\NataeroDispatchSneakpeek::class,
            Commands\NataeroFetchResults::class,
            Commands\NataeroBackfillHyper1FOSCommand::class,
            Commands\NataeroCleanupOrphanedTasksCommand::class,
        ]);
    }
}
