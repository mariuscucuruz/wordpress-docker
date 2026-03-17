<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Models\ServiceFunction;
use Illuminate\Database\Eloquent\Builder;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsMode;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Clickonmedia\Rekognition\Enums\RekognitionTypes;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\ServiceFunctionsEnum;

trait ServiceActions
{
    public function isActionManual(RekognitionTypes|ServiceFunctionsEnum $serviceAction, FunctionsType $type): bool
    {
        return $this->checkActionModeByType($serviceAction, FunctionsMode::Manual, $type);
    }

    public function checkActionModeByType(
        string|RekognitionTypes|ServiceFunctionsEnum $serviceAction,
        FunctionsMode $mode,
        FunctionsType $type
    ): bool {
        $actionValue = $serviceAction instanceof ServiceFunctionsEnum ? $serviceAction->value : $serviceAction;
        $typeValue = $type->value;

        return $this->serviceFunctions()
            ->where('action', $actionValue)
            ->where('mode', $mode)
            ->where('type', $typeValue)
            ->exists();
    }

    public function serviceFunctions(): HasMany
    {
        return $this->hasMany(ServiceFunction::class);
    }

    public function isActionAutomatic(string|RekognitionTypes|ServiceFunctionsEnum $serviceAction, FunctionsType $type): bool
    {
        if ($this->checkActionModeByType($serviceAction, FunctionsMode::Automatic, $type)) {
            return true;
        }

        $actionValue = $serviceAction instanceof ServiceFunctionsEnum ? $serviceAction->value : $serviceAction;
        $typeValue = $type->value;

        return $this->serviceFunctions()
            ->where('action', $actionValue)
            ->where('type', $typeValue)
            ->doesntExist();
    }

    public function isActionDisabled(string|RekognitionTypes|ServiceFunctionsEnum $serviceAction, ?FunctionsType $type): bool
    {
        return $this->checkActionModeByType($serviceAction, FunctionsMode::Disabled, $type);
    }

    public function scopeServiceFunctionAutomatic(Builder $query, ServiceFunctionsEnum $serviceAction, FunctionsType $type): Builder
    {
        return $query->whereDoesntHave('serviceFunctions', fn (Builder $query) => $query
            ->where('action', $serviceAction->value)
            ->where('type', $type->value)
        )->orWhereHas('serviceFunctions', fn (Builder $query) => $query
            ->where('action', $serviceAction->value)
            ->where('mode', FunctionsMode::Automatic)
            ->where('type', $type->value)
        );
    }

    public function scopeServiceFunctionEnabled(Builder $query, string|ServiceFunctionsEnum $serviceAction, FunctionsType $type): Builder
    {
        $actionValue = $serviceAction instanceof ServiceFunctionsEnum ? $serviceAction->value : $serviceAction;
        $typeValue = $type->value;

        // first part checks if the service does not have the function at all - so it is enabled by default
        // second part checks if the service doesn't have the function disabled
        return $query->whereDoesntHave('serviceFunctions', fn (Builder $query) => $query
            ->where('action', $actionValue)
            ->where('type', $typeValue)
        )->orWhereDoesntHave('serviceFunctions', fn (Builder $query) => $query
            ->where('action', $actionValue)
            ->where('mode', FunctionsMode::Disabled)
            ->where('type', $typeValue)
        );
    }
}
