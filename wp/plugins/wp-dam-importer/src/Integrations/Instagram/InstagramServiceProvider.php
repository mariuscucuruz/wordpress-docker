<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Instagram;

use MariusCucuruz\DAMImporter\BasePackageServiceProvider;

class InstagramServiceProvider extends BasePackageServiceProvider
{
    protected string $name = 'instagram';

    protected string $classname = Instagram::class;

    protected string $path = __DIR__ . '/..';

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom("{$this->path}/config/instagram.php", 'instagram');
        $this->mergeConfigFrom("{$this->path}/config/instagramGraph.php", 'instagramGraph');
        $this->mergeConfigFrom("{$this->path}/config/instagramBasicDisplay.php", 'instagramBasicDisplay');
    }
}
