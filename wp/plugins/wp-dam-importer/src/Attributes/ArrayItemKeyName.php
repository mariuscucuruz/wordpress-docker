<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class ArrayItemKeyName
{
    public function __construct(public string $keyName)
    {
        //
    }

    public function key(): string
    {
        return $this->keyName;
    }
}
