<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition;

use Aws\Rekognition\RekognitionClient;
use Illuminate\Support\ServiceProvider;
use Aws\TranscribeService\TranscribeServiceClient;

class RekognitionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        // $this->loadRoutesFrom(__DIR__.'/routes.php');

        $this->bootForConsole();
        // ServiceProvider::addProviderToBootstrapFile(self::class);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rekognition.php', 'rekognition');

        $awsConfig = [
            'region'  => config('rekognition.region'),
            'version' => config('rekognition.version'),
        ];

        if (! config('app.disable_aws_credentials')) {
            $awsConfig['credentials'] = config('rekognition.credentials');
        }
        $this->app->singleton('rekognitionClient', function ($app) use ($awsConfig) {
            return new rekognitionClient($awsConfig);
        });
        $this->app->singleton('TranscribeServiceClient', function ($app) use ($awsConfig) {
            return new TranscribeServiceClient($awsConfig);
        });
        $this->app->singleton('rekognition', function ($app) {
            return new Rekognition;
        });
    }

    public function provides(): array
    {
        return ['rekognition'];
    }

    protected function bootForConsole(): void
    {
        $this->publishes([
            __DIR__ . '/../config/rekognition.php' => config_path('rekognition.php'),
        ], 'rekognition.config');

        $this->commands([
            Commands\MediaRekognitionForgetCommand::class,
            Commands\EnqueueRekognitionCommand::class,
            Commands\RetrieveRekognitionCommand::class,
            Commands\ReProcessCelebritiesCommand::class,
        ]);
    }
}
