<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum MediaStorageType: string
{
    case originals = 'originals';
    case thumbnails = 'thumbnails';
    case derivatives = 'derivatives';
    case mediaconvert = 'mediaconvert';
    case sneakpeeks = 'sneakpeeks';
    case reports = 'reports';
    case data = 'data';
    case uploads = 'uploads';
}
