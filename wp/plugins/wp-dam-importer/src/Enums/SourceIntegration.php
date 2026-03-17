<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

use Illuminate\Support\Collection;

enum SourceIntegration: string
{
    case YouTube = 'youtube';
    case TikTok = 'tiktok';
    case TikTokAds = 'tiktokads';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Bynder = 'bynder';
    case Box = 'box';
    case Dropbox = 'dropbox';
    case GoogleDrive = 'googledrive';
    case GoogleAds = 'googleads';
    case MetaAds = 'metaads';
    case Pinterest = 'pinterest';
    case Acquia = 'acquia';
    case AdobeExperienceManager = 'adobeexperiencemanager';
    case Aprimo = 'aprimo';
    case Egnyte = 'egnyte';
    case FrameIo = 'frameio';
    case Vimeo = 'vimeo';
    case Nuxeo = 'nuxeo';
    case OneDrive = 'onedrive';
    case Peach = 'peach';
    case S3 = 's3';
    case SharePoint = 'sharepoint';
    case Brandfolder = 'brandfolder';
    case Frontify = 'frontify';

    public static function VulcanServices(): Collection
    {
        return collect([
            self::YouTube,
            self::GoogleAds,
        ]);
    }

    public static function hasPollMetrics(): array
    {
        return [
            self::MetaAds->value,
            self::GoogleAds->value,
        ];
    }

    public static function hasSyncMarketingTree(): array
    {
        return [
            self::MetaAds->value,
        ];
    }
}
