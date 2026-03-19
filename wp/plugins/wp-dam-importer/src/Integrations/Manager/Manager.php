<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Manager;

use Str;
use Aws\S3\S3Client;
use ReflectionClass;
use Illuminate\Routing\Redirector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\Response;
use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\DTOs\IntegrationDefinition;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use MariusCucuruz\DAMImporter\Services\StorageService;

abstract class Manager
{
    use Loggable;

    public $storage; // Adding type will cause tests to fail

    public ?Collection $metas;

    public ?array $customSettingKeys = null;

    public ?S3Client $s3Client;

    public function __construct(public ?Service $service = null, public ?Collection $settings = null)
    {
        $this->metas = $this->getMetadata();
        $this->storage = new StorageService($settings);
        $this->s3Client = $this->storage->getClient();

        $this->initialize();
    }

    public static function getServiceName(): string
    {
        return strtolower(string: static::getInterfaceType());
    }

    public static function getInterfaceType(): string
    {
        $parts = explode('\\', static::class);

        return end($parts);
    }

    abstract public static function definition(): IntegrationDefinition;

    public function getMetadata(): Collection
    {
        $this->service?->loadMissing('metas');

        return $this->service?->metas ?? new Collection;
    }

    public function redirectTo(string $url): RedirectResponse
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');

        return redirect(filter_var($url, FILTER_SANITIZE_URL))->send();
    }

    public function getSettings(?array $customKeys = []): array
    {
        $childClassName = strtolower((new ReflectionClass(static::class))->getShortName());
        $settingKeys = ! empty($customKeys) ? $customKeys : array_keys(config("{$childClassName}.settings", []));

        $settings = [];

        foreach ($settingKeys as $settingKey) {
            $payload = $this->settings?->firstWhere('name', $settingKey)?->payload;

            if (! empty($payload)) {
                $settings[$settingKey] = $payload;
            }
        }

        return $settings;
    }

    public function getInvalidSettingsKeys(): array
    {
        $settings = $this->getSettings($this->customSettingKeys);
        $invalidKeys = [];

        $collectInvalidKeys = function (array $array) use (&$collectInvalidKeys, &$invalidKeys) {
            foreach ($array as $key => $value) {
                if (is_array($value)) {
                    $collectInvalidKeys($value);
                } elseif (empty($value)) {
                    $invalidKeys[] = $key;
                }
            }
        };
        $collectInvalidKeys($settings);

        return $invalidKeys;
    }

    public function redirectToServiceUrl(?string $serviceId = null, ?int $httpCode = Response::HTTP_CREATED): Redirector|RedirectResponse
    {
        $serviceId ??= $this->service?->id;

        $url = (! empty($this->service) && Str::isUuid($serviceId))
            ? "/services/settings/{$serviceId}"
            : '/catalogue';

        return redirect(url($url), $httpCode);
    }
}
