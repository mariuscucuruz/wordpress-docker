<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Manager;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Container\Container;

abstract class BasePackageServiceProvider extends ServiceProvider
{
    protected string $name = '';

    protected string $classname = '';

    protected string $path = __DIR__;

    protected array $registerCommands = [];

    protected array $registerProviders = [];

    protected ?string $tagname = 'integration';

    public function provides(): array
    {
        return [$this->name];
    }

    public function boot(): void
    {
        $this->loadConfig();
    }

    public function register(): void
    {
        $this->loadConfig()
            ->loadRoutes()
            ->loadMigrations();

        $this->app->singleton($this->name, function (Container $app, $params) {
            return new $this->classname(...$params);
        });

        if (! empty($this->registerProviders)) {
            array_map(
                fn (string $provider) => $this->app->register($provider),
                $this->registerProviders
            );
        }

        $this->app->alias($this->classname, $this->name);

        if (filled($this->tagname)) {
            $this->app->tag($this->classname, $this->tagname);
        }
    }

    protected function loadConfig(): self
    {
        $configPath = "{$this->path}/config/{$this->name}.php";

        if ($this->app->runningInConsole()) {
            if (file_exists($configPath)) {
                $this->publishes([$configPath => $this->app->configPath("{$this->name}.php")], "{$this->name}.config");
            }

            if (collect($this->registerCommands)->isNotEmpty()) {
                $this->commands(collect($this->registerCommands)->unique()->toArray());
            }
        }

        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, $this->name);
        }

        return $this;
    }

    protected function loadRoutes(): self
    {
        $routesPath = "{$this->path}/routes";

        if (file_exists($routesPath)) {
            foreach (glob("{$routesPath}/*.php") as $fileName) {
                $this->loadRoutesFrom($fileName);
            }
        }

        return $this;
    }

    protected function loadMigrations(): self
    {
        $migrationsPath = "{$this->path}/database/migrations";

        if (file_exists($migrationsPath)) {
            $this->loadMigrationsFrom($migrationsPath);
        }

        return $this;
    }
}
