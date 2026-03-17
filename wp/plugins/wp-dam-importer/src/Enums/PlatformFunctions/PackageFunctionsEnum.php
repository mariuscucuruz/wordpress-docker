<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums\PlatformFunctions;

use MariusCucuruz\DAMImporter\Traits\BaseFunctions;
use MariusCucuruz\DAMImporter\Models\PackageFunction;
use Illuminate\Database\Eloquent\Builder;
use Clickonmedia\Rekognition\Enums\RekognitionTypes;

enum PackageFunctionsEnum: string
{
    use BaseFunctions;

    public static function getDefaultActions(string $packageName): array
    {
        $actions = [];

        foreach (FunctionsType::names() as $type) {
            if (FunctionsType::hasActionMethods($type)) {
                $actions[$type] = self::{"{$type}Actions"}($packageName);
            }
        }

        return $actions;
    }

    private static function defaultActions(
        ServiceFunctionsEnum|RekognitionTypes $action,
        string $serviceType,
        string $description,
        string $label,
        array $aiObjects,
        string $fileType,
        string $packageName
    ): array {
        $data = [
            'label'        => $label,
            'action'       => $action->value,
            'service_type' => $serviceType,
            'description'  => $description,
            'ai_objects'   => $aiObjects,
        ];

        $dynamicData = [
            'mode' => PackageFunction::where('package_name', $packageName)
                ->where(fn (Builder $query) => $query
                    ->where('type', $fileType)
                    ->where('action', $action)
                )->first()?->mode ?? FunctionsMode::Automatic->value,
        ];

        return [...$data, ...$dynamicData];
    }
}
