<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Enums;

enum CollectionType: string
{
    case SmartCollection = 'smart_collection';
    case BasicCollection = 'basic_collection';
}
