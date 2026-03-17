<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Facebook;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class FacebookServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'facebook';

    protected string $classname = Facebook::class;

    protected string $path = __DIR__ . '/..';
}
