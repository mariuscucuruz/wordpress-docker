<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\LinkedIn;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class LinkedInServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'linkedin';

    protected string $classname = LinkedIn::class;

    protected string $path = __DIR__;

    protected ?string $tagname = 'integration';
}
