<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frameio\FrameioClient\Http\Clients;

interface FrameioHttpClientInterface
{
    public function send($url, $method, $body, $headers = []);
}
