<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Models;

interface ModelInterface
{
    public function getData();

    public function getDataProperty($property);
}
