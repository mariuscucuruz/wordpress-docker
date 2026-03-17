<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums\PlatformFunctions;

use Clickonmedia\Rekognition\Enums\RekognitionTypes;

enum FunctionsLevel: string
{
    case Lite = 'lite';
    case Intense = 'intense';

    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function lite(): array
    {
        return [
            FunctionsType::Audio->value => [
                RekognitionTypes::TRANSCRIBES->value,
                ServiceFunctionsEnum::MUSIC->value,
            ],
            FunctionsType::Video->value => [
                RekognitionTypes::TRANSCRIBES->value,
                RekognitionTypes::TEXTS->value,
                RekognitionTypes::CELEBRITIES->value,
                ServiceFunctionsEnum::MUSIC->value,
            ],
            FunctionsType::Image->value => [
                RekognitionTypes::TEXTS->value,
                RekognitionTypes::CELEBRITIES->value,
            ],
        ];
    }
}
