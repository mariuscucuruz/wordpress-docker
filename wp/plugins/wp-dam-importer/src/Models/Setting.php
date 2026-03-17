<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Models;

use Exception;
use MariusCucuruz\DAMImporter\Traits\RecordActivity;
use MariusCucuruz\DAMImporter\Enums\SettingVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Setting extends Model
{
    use HasFactory;
    use RecordActivity;

    public static function validateSettingsForService(string $serviceName, ?array $dataToValidate = []): ?array
    {
        $validationRules = [];

        foreach (config("{$serviceName}.settings", []) as $settingArr) {
            // $settingRequired = (bool) data_get($settingArr, 'required');
            $settingName = (string) data_get($settingArr, 'name', '');
            $configName = strtolower(str_ireplace("{$serviceName}_", '', $settingName));

            if ($settingRules = data_get($settingArr, 'rules')) {
                $validationRules[$configName] = $settingRules;
            }
        }

        if (empty($validationRules)) {
            return $dataToValidate;
        }

        return Validator::make($dataToValidate, $validationRules)->validate();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(Storage::class, 'service_id');
    }

    public function getPayloadAttribute(mixed $value): mixed
    {
        if ($value === '""') {
            return null;
        }

        try {
            return decrypt(json_decode($value));
        } catch (Exception $exception) {
            logger()->error('Setting payload decryption error', [
                'setting_id' => $this->id,
                'message'    => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function setPayloadAttribute(mixed $value): void
    {
        $encrypted = encrypt($value);
        $this->attributes['payload'] = json_encode($encrypted);
    }

    public function setGroupAttribute(mixed $value): void
    {
        $slug = str_contains($value, '.') ? $value : str()->slug($value, '');
        $this->attributes['group'] = $slug;
    }

    public function duplicateWithNewTitle(): self
    {
        $newSetting = $this->replicate(['original', 'created_at', 'updated_at']);

        $timestamp = now()->format('Y-m-d-H-i-s');
        $newSetting->title = "{$this->title}-{$timestamp}";
        $newSetting->is_original = false;
        $newSetting->save();

        return $newSetting;
    }

    protected function casts(): array
    {
        return [
            'locked'      => 'boolean',
            'visibility'  => SettingVisibility::class,
            'is_original' => 'boolean',
        ];
    }
}
