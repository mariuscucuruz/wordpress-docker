<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use Exception;
use RuntimeException;

trait SettingsFallback
{
    public static function get($name)
    {
        $static = new static;

        try {
            if (property_exists($static, $name)) {
                return $static->{$name};
            }

            throw new RuntimeException("Property {$name} does not exist on " . static::class);
        } catch (Exception $e) {
            logger()->error(__CLASS__ . ' ' . __FUNCTION__, [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return env($name);
        }
    }
}
