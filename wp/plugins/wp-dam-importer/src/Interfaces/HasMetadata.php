<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Interfaces;

interface HasMetadata
{
    public function getMetadataAttributes(?array $properties): array;
}
