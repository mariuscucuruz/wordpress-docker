<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Models;

use Exception;
use Carbon\CarbonImmutable;
use MariusCucuruz\DAMImporter\Traits\Metable;
use MariusCucuruz\DAMImporter\Traits\Teamable;
use MariusCucuruz\DAMImporter\Traits\Batchable;
use MariusCucuruz\DAMImporter\Traits\Packagable;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;
use MariusCucuruz\DAMImporter\Traits\RecordActivity;
use MariusCucuruz\DAMImporter\Traits\ServiceActions;
use MariusCucuruz\DAMImporter\Enums\PackageChannelTypes;
use MariusCucuruz\DAMImporter\Models\Scopes\ServiceScope;
use MariusCucuruz\DAMImporter\DTO\OAuthToken;
use MariusCucuruz\DAMImporter\Enums\OAuthTokenType;
use MariusCucuruz\DAMImporter\Manager\SourcePackageManager;
use MariusCucuruz\DAMImporter\Manager\StoragePackageManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

#[ScopedBy([ServiceScope::class])]
class Service extends Model
{
    use Batchable,
        HasFactory,
        HasUuids,
        Metable,
        Packagable,
        Prunable,
        RecordActivity,
        ServiceActions,
        SoftDeletes,
        Teamable;

    public ?string $name = null;

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function scopeActive(): Builder|self
    {
        return $this->where('status', IntegrationStatus::ACTIVE);
    }

    public function scopeInactive(): Builder|self
    {
        return $this->where('status', IntegrationStatus::INACTIVE);
    }

    public function storages(): MorphToMany
    {
        return $this->morphedByMany(Storage::class, 'backupable')
            ->withPivot([
                'backup_mode',
                'backup_interval',
                'backup_started_at',
            ]);
    }

    public function prunable(): self|Builder
    {
        return static::where('deleted_at', '<=', now()->subMonth());
    }

    public function accessToken(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => is_string($value) ? decrypt($value, false) : null,
            set: fn (mixed $value) => (is_string($value) && ! empty($value)) ? encrypt($value, false) : null,
        );
    }

    public function refreshToken(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => is_string($value) ? decrypt($value, false) : null,
            set: fn (mixed $value) => (is_string($value) && ! empty($value)) ? encrypt($value, false) : null,
        );
    }

    public function oAuthToken(): ?OAuthToken
    {
        try {
            return new OAuthToken(
                accessToken: $this->access_token,
                accessExpiresAt: CarbonImmutable::parse($this->expires),
                refreshToken: $this->refresh_token,
                refreshExpiresAt: $this->refresh_token_expires_at
                    ? CarbonImmutable::parse($this->refresh_token_expires_at)
                    : null,
                scopes: null,
                type: OAuthTokenType::Bearer,
                createdAt: CarbonImmutable::parse($this->created_at)
            );
        } catch (Exception $e) {
            logger()->error($e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return null;
    }

    public function getDefaultOptions(): array
    {
        $serviceSettings = config("{$this->name}.defaults", []);
        $defaultSettings = config('manager.defaults', []);

        return $serviceSettings + $defaultSettings;
    }

    public function getPackage(): null|SourcePackageManager|StoragePackageManager
    {
        if (filled($this->interface_type)) {
            try {
                /** @var SourcePackageManager|StoragePackageManager $app */
                return new $this->interface_type($this, $this->settings()->get());
            } catch (Exception $e) {
                $class = class_basename($this->interface_type);

                logger()->error("Failed to load package {$class}: {$e->getMessage()}", $e->getTrace());
            }
        }

        return null;
    }


    protected function photo(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, mixed $attributes) => str_starts_with($value ?? '', 'http')
                ? $value
                : presigned_url($value),
        );
    }

    protected function apiKey(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => $value ? decrypt($value) : null,
            set: fn (mixed $value) => $value ? encrypt($value) : null,
        );
    }

    protected function password(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => $value ? decrypt($value) : null,
            set: fn (mixed $value) => $value ? encrypt($value) : null,
        );
    }

    public function serviceFunctions(): HasMany
    {
        return $this->hasMany(ServiceFunction::class);
    }

    protected function casts(): array
    {
        return [
            'status'                   => IntegrationStatus::class,
            'sync_mode'                => PackageSyncMode::class,
            'sync_interval'            => PackageInterval::class,
            'channel_type'             => PackageChannelTypes::class,
            'sync_started_at'          => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'refresh_token_updated_at' => 'datetime',
            'options'                  => 'array',
            'folder_ids'               => 'array',
        ];
    }

    protected function pruning(): void
    {
        $this->load('files', 'importGroups');

        $this->files()->withTrashed()->each(fn (File $file) => dispatch(
            $file->delete();
        ));
    }
}
