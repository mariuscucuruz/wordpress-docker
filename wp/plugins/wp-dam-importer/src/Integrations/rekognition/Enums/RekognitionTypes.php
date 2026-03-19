<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Enums;

enum RekognitionTypes: string
{
    case TEXTS = 'texts';
    case TRANSCRIBES = 'transcribes';
    case SEGMENT = 'segments';
    case CELEBRITIES = 'celebrities';

    /** @deprecated */
    case LABELS = 'labels';
    case LANDMARKS = 'landmarks';
    case MODERATIONS = 'moderations';
    case EMOTIONS = 'emotions';
    case GENDERS = 'genders';
    case FACES = 'faces';
    case AGES = 'ages';

    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function getAiObjectTitles(): array
    {
        return [
            'transcript'  => self::TRANSCRIBES->value,
            'ocr'         => self::TEXTS->value,
            'celebrities' => self::CELEBRITIES->value,
        ];
    }
}
