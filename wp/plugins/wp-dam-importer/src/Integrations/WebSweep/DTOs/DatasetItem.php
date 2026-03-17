<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\DTOs;

use MariusCucuruz\DAMImporter\DTOs\BaseDTO;

class DatasetItem extends BaseDTO
{
    public ?string $text = null;

    public ?string $fromUrl = null;

    public ?string $url = null;

    public ?string $fileId = null;

    public ?string $name = null;

    public ?string $extension = null;

    public ?string $type = null;
}
