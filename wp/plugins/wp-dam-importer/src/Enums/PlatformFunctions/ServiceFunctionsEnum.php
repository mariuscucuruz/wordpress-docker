<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums\PlatformFunctions;

use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Traits\BaseFunctions;
use Illuminate\Database\Eloquent\Builder;
use Clickonmedia\Rekognition\Enums\RekognitionTypes;

enum ServiceFunctionsEnum: string
{
    use BaseFunctions;

    case MUSIC = 'music';

    public static function getDefaultActions(FunctionsType|string $assetType = '', string $serviceType = '', ?Service $service = null): array
    {
        if ($assetType instanceof FunctionsType) {
            $method = "{$assetType->value}Actions";

            if (! method_exists(self::class, $method)) {
                return [];
            }

            return array_filter(
                self::{$method}(),
                fn (array $item) => $item['service_type'] === $serviceType
            );
        }

        $actions = [];

        foreach (FunctionsType::names() as $type) {
            $method = "{$type}Actions";

            if (FunctionsType::hasActionMethods($type)) {
                $actions[$type] = self::{$method}($service);
            }
        }

        return $actions;
    }

    private static function defaultActions(
        self|RekognitionTypes $action,
        string $serviceType,
        string $description,
        string $label,
        array $aiObjects,
        string $fileType,
        ?Service $service = null
    ): array {
        $dynamicData = [
            'mode' => FunctionsMode::Automatic->value,
        ];
        $data = [
            'label'        => $label,
            'action'       => $action->value,
            'service_type' => $serviceType,
            'description'  => $description,
            'ai_objects'   => $aiObjects,
        ];

        if ($service) {
            $service->loadMissing('serviceFunctions');

            $dynamicData = [
                'mode' => $service->serviceFunctions()->where(fn (Builder $query) => $query
                    ->where('type', $fileType)
                    ->where('action', $action))
                    ->first()?->mode ?? FunctionsMode::Automatic->value,
            ];
        }

        return [...$data, ...$dynamicData];
    }

    private static function aiActions(?Service $service = null): array
    {
        return [];
    }

    private static function pdfActions(?Service $service = null): array
    {
        return [];
    }
}
