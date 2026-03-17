<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\GoogleAds;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class GoogleAdsServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'googleads';

    protected string $classname = GoogleAds::class;

    protected string $path = __DIR__;
}
