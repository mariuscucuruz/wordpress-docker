<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Interfaces;

interface HasRemoteServiceId
{
    public function getRemoteServiceId(): string;
}
