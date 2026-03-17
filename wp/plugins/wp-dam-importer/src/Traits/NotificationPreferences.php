<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use MariusCucuruz\DAMImporter\Enums\NotificationEnum;

trait NotificationPreferences
{
    public function getUserNotificationPreferences($notifiable, NotificationEnum $notificationType): array
    {
        $preferences = $notifiable->preferences()->where('key', $notificationType->value)->first();

        $json = $preferences ? $preferences->value : '';

        return array_keys(
            array_filter(
                json_decode(
                    $json,
                    true
                ) ?? [],
                fn ($isEnabled) => $isEnabled === true
            )
        );
    }
}
