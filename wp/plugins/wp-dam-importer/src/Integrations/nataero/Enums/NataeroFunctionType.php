<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Nataero\Enums;

enum NataeroFunctionType: string
{
    case SCENE_DETECTION = 'SCENEDETECTION';
    case HYPER1 = 'HYPER1';

    case CONVERT = 'CONVERT';
    case MEDIAINFO = 'MEDIAINFO';
    case EXIF = 'EXIF';
    case SNEAKPEEK = 'SNEAKPEEK';
}
