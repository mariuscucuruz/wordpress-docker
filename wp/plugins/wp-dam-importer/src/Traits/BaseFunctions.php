<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use Clickonmedia\Rekognition\Enums\RekognitionTypes;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\ServiceFunctionsEnum;

trait BaseFunctions
{
    public static function names(): array
    {
        $rekognitionTypes = array_column(RekognitionTypes::cases(), 'value');
        $extraFunction = array_column(self::cases(), 'value');

        return [...$rekognitionTypes, ...$extraFunction];
    }

    public static function actions(string $type, string $fileType, Service|string|null $arg): array
    {
        return match ($type) {
            RekognitionTypes::TRANSCRIBES->value => self::defaultActions(RekognitionTypes::TRANSCRIBES,
                config('rekognition.name', 'rekognition'),
                'This converts speech into text.',
                'Transcript',
                [RekognitionTypes::TRANSCRIBES->value],
                $fileType,
                $arg
            ),
            //            RekognitionTypes::LABELS->value => self::defaultActions(RekognitionTypes::LABELS,
            //                config('rekognition.name', 'rekognition'),
            //                'This is a scan of objects that appear in your assets, including landmarks and labels.',
            //                'Objects',
            //                [RekognitionTypes::LANDMARKS->value, RekognitionTypes::LABELS->value],
            //                $fileType,
            //                $arg
            //            ),
            RekognitionTypes::TEXTS->value => self::defaultActions(RekognitionTypes::TEXTS,
                config('rekognition.name', 'rekognition'),
                'This is a scan of print and handwritten text in your assets.',
                'Optical Character Recognition (OCR)',
                [RekognitionTypes::TEXTS->value],
                $fileType,
                $arg
            ),
            //            RekognitionTypes::FACES->value => self::defaultActions(RekognitionTypes::FACES,
            //                config('rekognition.name', 'rekognition'),
            //                'This is a scan of faces.',
            //                'Sentiments',
            //                [
            //                    RekognitionTypes::FACES->value,
            //                    RekognitionTypes::AGES->value,
            //                    RekognitionTypes::EMOTIONS->value,
            //                    RekognitionTypes::GENDERS->value,
            //                ],
            //                $fileType,
            //                $arg
            //            ),
            //            RekognitionTypes::MODERATIONS->value => self::defaultActions(RekognitionTypes::MODERATIONS,
            //                config('rekognition.name', 'rekognition'),
            //                'This detects potential brand safety risks in your assets.',
            //                'Content Moderation',
            //                [RekognitionTypes::MODERATIONS->value],
            //                $fileType,
            //                $arg
            //            ),
            RekognitionTypes::CELEBRITIES->value => self::defaultActions(RekognitionTypes::CELEBRITIES,
                config('rekognition.name', 'rekognition'),
                'This detects celebrities',
                'Celebrities',
                [RekognitionTypes::CELEBRITIES->value],
                $fileType,
                $arg
            ),
            RekognitionTypes::SEGMENT->value => self::defaultActions(RekognitionTypes::SEGMENT,
                config('rekognition.name', 'rekognition'),
                'This detects video segments in your assets.',
                'Segmentable',
                [RekognitionTypes::SEGMENT->value],
                $fileType,
                $arg
            ),
            ServiceFunctionsEnum::MUSIC->value => self::defaultActions(ServiceFunctionsEnum::MUSIC,
                config('acrcloud.name', 'acrcloud'),
                'This detects music tracks in your assets..',
                'Music',
                [ServiceFunctionsEnum::MUSIC->value],
                $fileType,
                $arg
            ),
        };
    }

    public static function videoActions(Service|string|null $arg = null): array
    {
        return [
            RekognitionTypes::TRANSCRIBES->value => self::actions(RekognitionTypes::TRANSCRIBES->value, FunctionsType::Video->value, $arg),
            //           RekognitionTypes::LABELS->value      => self::actions(RekognitionTypes::LABELS->value, FunctionsType::Video->value, $arg),
            RekognitionTypes::TEXTS->value => self::actions(RekognitionTypes::TEXTS->value, FunctionsType::Video->value, $arg),
            //           RekognitionTypes::FACES->value       => self::actions(RekognitionTypes::FACES->value, FunctionsType::Video->value, $arg),
            RekognitionTypes::CELEBRITIES->value => self::actions(RekognitionTypes::CELEBRITIES->value, FunctionsType::Video->value, $arg),
            //           RekognitionTypes::MODERATIONS->value => self::actions(RekognitionTypes::MODERATIONS->value, FunctionsType::Video->value, $arg),
            RekognitionTypes::SEGMENT->value   => self::actions(RekognitionTypes::SEGMENT->value, FunctionsType::Video->value, $arg),
            ServiceFunctionsEnum::MUSIC->value => self::actions(ServiceFunctionsEnum::MUSIC->value, FunctionsType::Video->value, $arg),
        ];
    }

    public static function imageActions(Service|string|null $arg = null): array
    {
        return [
            //            RekognitionTypes::LABELS->value      => self::actions(RekognitionTypes::LABELS->value, FunctionsType::Image->value, $arg),
            RekognitionTypes::TEXTS->value => self::actions(RekognitionTypes::TEXTS->value, FunctionsType::Image->value, $arg),
            //            RekognitionTypes::FACES->value       => self::actions(RekognitionTypes::FACES->value, FunctionsType::Image->value, $arg),
            RekognitionTypes::CELEBRITIES->value => self::actions(RekognitionTypes::CELEBRITIES->value, FunctionsType::Image->value, $arg),
            //            RekognitionTypes::MODERATIONS->value => self::actions(RekognitionTypes::MODERATIONS->value, FunctionsType::Image->value, $arg),
        ];
    }

    public static function audioActions(Service|string|null $arg = null): array
    {
        return [
            RekognitionTypes::TRANSCRIBES->value => self::actions(RekognitionTypes::TRANSCRIBES->value, FunctionsType::Audio->value, $arg),
            ServiceFunctionsEnum::MUSIC->value   => self::actions(ServiceFunctionsEnum::MUSIC->value, FunctionsType::Audio->value, $arg),
        ];
    }
}
