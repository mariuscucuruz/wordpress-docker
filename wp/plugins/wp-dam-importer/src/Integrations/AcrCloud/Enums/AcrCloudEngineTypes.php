<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AcrCloud\Enums;

/**
 * @link https://docs.acrcloud.com/reference/console-api/file-scanning ACR File Scanning API documentation
 */
enum AcrCloudEngineTypes: int
{
    case AUDIO_FINGERPRINTING = 1;
    case COVER_SONGS = 2;
    case AUDIO_AND_COVER_SONGS = 3;
    case SPEECH_TO_TEXT = 4;
}
