<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use MariusCucuruz\DAMImporter\Enums\AdminSettingEnum;
use Illuminate\Database\Eloquent\Builder;

trait AdminSettingKeys
{
    public static function isDownloadFromServiceEnabled(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::DOWNLOAD_FROM_SERVICE->value, true)->exists();
    }

    public static function isGoogleMultimodalEnabled(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::ENABLE_GOOGLE_MULTIMODAL->value, true)->exists();
    }

    public static function isNataeroConvertEnabled(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::NATAERO_CONVERT_ENABLED->value, true)->exists();
    }

    public static function isPersonalTeamEnabled(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::PERSONAL_TEAM_ENABLED->value, true)->exists();
    }

    public static function isPersonalTeamDisabled(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::PERSONAL_TEAM_ENABLED->value, false)->exists();
    }

    public static function canLoginByEmail(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::LOGIN_BY_EMAIL->value, true)->exists();
    }

    public static function canResetPassword(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::CAN_RESET_PASSWORD->value, true)->exists();
    }

    public static function cannotResetPassword(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::CAN_RESET_PASSWORD->value, false)->exists();
    }

    public static function isAutomaticDownloadEnabled(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::AUTOMATIC_DOWNLOAD->value, true)->exists();
    }

    public static function isAutomaticAnalyzeEnabled(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::AUTOMATIC_ANALYZE->value, true)->exists();
    }

    public static function isNataeroAutoDispatchEnabled(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::NATAERO_AUTO_DISPATCH->value, true)->exists();
    }

    public static function isACRCloudEnabled(): bool
    {
        return static::isEnabled(AdminSettingEnum::ENABLE_ACR_CLOUD);
    }

    public static function isACRCloudShown(): bool
    {
        return static::isEnabled(AdminSettingEnum::SHOW_ACR_CLOUD);
    }

    public static function isExifEnabled(): bool
    {
        return static::isEnabled(AdminSettingEnum::ENABLE_EXIF);
    }

    public static function isMediaInfoEnabled(): bool
    {
        return static::isEnabled(AdminSettingEnum::ENABLE_MEDIAINFO);
    }

    public static function isSneakpeekEnabled(): bool
    {
        return static::isEnabled(AdminSettingEnum::ENABLE_SNEAKPEEK);
    }

    public static function isYoutubeFEEnabled(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::YOUTUBE_FE_PLAYABLE_ENABLED->value, true)->exists();
    }

    public static function nataeroJobLimit(): int
    {
        return static::where('key', AdminSettingEnum::NATAERO_JOB_LIMIT->value)->first()?->value ?? 200;
    }

    public static function isAltHomepageEnabled(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::ALT_HOMEPAGE_ENABLED->value, true)->exists();
    }

    public static function isLlmChatEnabled(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::LLM_CHAT_ENABLED->value, true)->exists();
    }

    public static function onlyShowCustomRegions(): bool
    {
        return static::whereJsonValueEquals(AdminSettingEnum::ONLY_SHOW_CUSTOM_REGIONS->value, true)->exists();
    }

    private static function whereJsonValueEquals(string $key, $value): Builder
    {
        $driver = config('database.default');
        $jsonValue = json_encode($value);

        return static::where('key', $key)
            ->where(function ($query) use ($driver, $jsonValue, $value) {
                if ($driver === 'pgsql') {
                    $query->whereRaw('value::jsonb @> ?::jsonb', [$jsonValue]);
                } elseif ($driver === 'mysql') {
                    $query->where(fn ($query) => $query->whereRaw('value = "true"')->orWhereRaw('value = true'));
                } elseif ($driver === 'sqlite') {
                    $query->whereRaw("json_extract(value, '$') = ?", [$value]);
                }
            });
    }

    public static function isEnabled(AdminSettingEnum $key): bool
    {
        return ! static::where('key', $key->value)->exists() ||
            static::whereJsonValueEquals($key->value, true)->exists();
    }
}
