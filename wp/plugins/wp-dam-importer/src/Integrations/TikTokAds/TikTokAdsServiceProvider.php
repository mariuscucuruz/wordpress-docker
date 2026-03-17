<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class TikTokAdsServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'tiktokads';

    protected string $classname = TikTokAds::class;

    protected string $path = __DIR__;

    protected ?string $tagname = 'integration';
}
