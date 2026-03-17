<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Attributes;

use Attribute;

#[Attribute]
readonly class MapArrayItemsTo
{
    public function __construct(public string $className)
    {
        //
    }
}
