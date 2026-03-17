<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\TikTokAds\Exceptions;

use Exception;

class TiktokApiException extends Exception
{
    const string DEFAULT_ERROR_MESSAGE = 'Tiktok Api Exception occurred.';

    public static function make(?string $message = self::DEFAULT_ERROR_MESSAGE): self
    {
        return new self($message);
    }
}
