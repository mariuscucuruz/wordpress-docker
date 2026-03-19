<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum FileOperationName: string
{
    case DOWNLOAD = 'download';
    case CONVERT = 'convert';
    case REKOGNITION = 'rekognition';
    case HYPER1 = 'hyper1';
    case SNEAKPEEK = 'sneakpeek';
    case ACRCLOUD = 'acrcloud';
    case MEDIAINFO = 'mediainfo';
    case EXIF = 'exif';
    case VERTEX = 'vertex';
    case MALWARE_SCAN = 'malware_scan';
    case THUMBNAIL = 'thumbnail'; // TODO: decide if this is going to be a separate sate!
}

// NOTE: don't make states for business logics,
//  something that has no dependency,
//  or something you want to use in UI, such as:
//  `TOO_SHORT`, `TOO_LONG`, `TOO_LARGE`, `CHILDREN_PROCESSING`
