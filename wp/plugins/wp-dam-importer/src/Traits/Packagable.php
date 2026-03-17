<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use Throwable;
use Illuminate\Support\Facades\Cache;
use MariusCucuruz\DAMImporter\Integrations\IntegrationRegistry;

trait Packagable
{
    public function getPackageAttribute(): mixed
    {
        return once(function () {
            if (! isset($this->attributes['interface_type'])) {
                return null;
            }

            $packageName = strtolower(class_basename($this->attributes['interface_type']));
            $cacheKey = 'package:' . $packageName . ':' . $this->attributes['id'] . ':settings';

            $settings = Cache::remember($cacheKey, now()->addMinutes(5), function () {
                if (! $this->relationLoaded('settings')) {
                    $this->load('settings');
                }

                return $this->settings;
            });

            $this->setRelation('settings', $settings);

            return rescue(function () {
                $packageName = strtolower(class_basename($this->attributes['interface_type']));

                return app($packageName, ['service' => $this, 'settings' => $this->settings]);
            }, function ($exception) {
                logger()->error($exception->getMessage());

                return null;
            });
        });
    }

    public function getInterfacesAttribute(): array
    {
        if (! $this->interface_type) {
            return [];
        }

        $resolvedType = IntegrationRegistry::find($this->interface_type)
            ? str_replace('Clickonmedia\\', 'Integrations\\', $this->interface_type)
            : $this->interface_type;

        return once(
            fn () => rescue(
                fn () => array_values(
                    array_map(
                        class_basename(...),
                        class_implements($resolvedType)
                    )
                ),
                function (Throwable $e) use ($resolvedType) {
                    logger()->critical("Failed to resolve package for {$resolvedType}: {$e->getMessage()}");

                    return [];
                }
            )
        );
    }
}
