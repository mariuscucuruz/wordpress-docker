<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Models;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use MariusCucuruz\DAMImporter\Traits\Metable;
use MariusCucuruz\DAMImporter\Enums\AssetType;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use MariusCucuruz\DAMImporter\Support\DateSupport;
use MariusCucuruz\DAMImporter\Traits\MultiModalable;
use MariusCucuruz\DAMImporter\Traits\RecordActivity;
use MariusCucuruz\DAMImporter\Traits\SearchableFile;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Models\Scopes\FileScope;
use MariusCucuruz\DAMImporter\Enums\FileOperationStatus;
use MariusCucuruz\DAMImporter\Enums\FileVisibilityStatus;
use MariusCucuruz\DAMImporter\Integrations\Exif\Traits\Exifable;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Traits\Nataeroable;
use MariusCucuruz\DAMImporter\Integrations\Folders\Traits\ParentAlbums;
use MariusCucuruz\DAMImporter\Integrations\AcrCloud\Traits\AcrCloudable;
use MariusCucuruz\DAMImporter\Integrations\Mediainfo\Traits\Mediainfoable;
use MariusCucuruz\DAMImporter\Integrations\Sneakpeek\Traits\Sneakpeekable;
use MariusCucuruz\DAMImporter\Integrations\Rekognition\Traits\Rekognizable;
use MariusCucuruz\DAMImporter\Integrations\Mediaconvert\Traits\Mediaconvertable;

#[ScopedBy([FileScope::class])]
class File extends Model
{
    use AcrCloudable,
        Exifable,
        HasFactory,
        HasUuids,
        Mediaconvertable,
        Mediainfoable,
        Metable,
        MultiModalable,
        Nataeroable,
        ParentAlbums,
        RecordActivity,
        Rekognizable,
        SearchableFile,
        Sneakpeekable,
        SoftDeletes;

    public string $id;
    public string $extension;
    public string $name;
    public ?string $url = null;
    public ?string $type = null;
    public ?string $mime_type = null;
 
    protected function casts(): array
    {
        return [
            'id'                  => 'string',
            'team_id'             => 'string',
            'visibility'          => FileVisibilityStatus::class,
            'created_time'        => 'datetime',
            'modified_time'       => 'datetime',
            'remote_last_seen_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function download(): bool
    {
        $this->load('service');

    }

    public function recordView(): void
    {
        $this->recordActivity([
            'record_event' => 'ViewFile',
            'record_data'  => $this->getActivityData(),
        ]);
    }

    public function recordDownload(): void
    {
        $this->recordActivity([
            'record_event' => 'DownloadFile',
            'record_data'  => $this->getActivityData(),
        ]);
    }

    public function totalViews(): int
    {
        return $this->activities()->where('record_event', 'ViewFile')->count();
    }

    public function totalDownloads(): int
    {
        return $this->activities()->where('record_event', 'DownloadFile')->count();
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'id')->withoutGlobalScopes();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'id')->withoutGlobalScopes();
    }

    public function allParentChildrenSuccessfullyProcessedAi(): bool
    {
        return $this->doAllParentChildrenMatchOperation(FileOperationName::REKOGNITION, FileOperationStatus::SUCCESS);
    }

    public function allParentChildrenSuccessfullyConverted(): bool
    {
        return $this->doAllParentChildrenMatchOperation(FileOperationName::CONVERT, FileOperationStatus::SUCCESS);
    }

    public function doAllParentChildrenMatchOperation(FileOperationName $operation, FileOperationStatus $status): bool
    {
        if (empty($this->parent)) {
            return false;
        }

        return $this->parent->children()
            ->whereDoesntHave('operationStates', fn ($q) => $q
                ->where('operation_name', $operation)
                ->where('status', '!=', $status)
            )
            ->doesntExist();
    }

    public function hasSuccessfulDownload(): bool
    {
        return $this->operationStates()
            ->where('operation_name', FileOperationName::DOWNLOAD)
            ->where('status', FileOperationStatus::SUCCESS)
            ->exists();
    }

    public function shouldSendToImageMagick()
    {
        if (! $this->hasSuccessfulDownload()) {
            return false;
        }

        return ($this->type === FunctionsType::Image->value && $this->extension !== 'gif')
            || $this->type === FunctionsType::PDF->value;
    }

    public function shouldSendToNataeroConvert(): bool
    {
        return $this->hasSuccessfulDownload() && in_array($this->type, [
            FunctionsType::Image->value,
            FunctionsType::PDF->value,
        ], true);
    }

    public function shouldSendToMediaConvert(): bool
    {
        if (! $this->hasSuccessfulDownload()) {
            return false;
        }

        return in_array($this->type, [
            FunctionsType::Video->value,
            FunctionsType::Audio->value,
        ], true) || $this->extension === 'gif';
    }

    public function determineDownloadOutcome(): bool
    {
        // Enforce constraints and reflect in legacy File.state for UI/tests
        if ($this->type === FunctionsType::Video->value &&
            $this->duration > config('manager.max_download_millisecond')) {
            $this->markFailure(
                operation: FileOperationName::DOWNLOAD,
                message: 'File too long',
                data: ['reason' => 'TOO_LONG']
            );

            return false; // stop!
        }

        if ($this->type === FunctionsType::Image->value &&
            $this->size > config('manager.max_size_byte')) {
            $this->markFailure(
                operation: FileOperationName::DOWNLOAD,
                message: 'File too large',
                data: ['reason' => 'TOO_LARGE']
            );

            return false; // stop!
        }

        return true; // continue!
    }

    public function generateLowerString($value): ?string
    {
        return $value ? str($value)->lower()->toString() : null;
    }

    public function generateSlug($value): ?string
    {
        return $value ? str($value)->slug()->limit(255, '')->toString() : null;
    }

    public function sanitizeAndLimitString($value): ?string
    {
        return $value ? str($value)->lower()->limit(255, '')->toString() : null;
    }

    protected function isRelationDirty($related): bool
    {
        if (empty($related)) {
            return false;
        }

        if ($related instanceof Collection) {
            return $related->isNotEmpty()
                && $related->contains(fn ($item) => $item->wasRecentlyCreated || $item->wasChanged());
        }

        return $related->wasRecentlyCreated || $related->wasChanged();
    }

    protected function createdTime(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, mixed $attributes) => DateSupport::formatTimestamp($value)
        );
    }

    protected function modifiedTime(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, mixed $attributes) => DateSupport::formatTimestamp($value)
        );
    }

    protected function downloadUrl(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, mixed $attributes) => $value ? presigned_url($value) : null
        );
    }

    protected function originalDownloadUrl(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, mixed $attributes) => $this->attributes['download_url']
        );
    }

    protected function viewUrl(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, mixed $attributes) => $value ? presigned_url($value) : null
        );
    }

    protected function originalViewUrl(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, mixed $attributes) => $this->attributes['view_url']
        );
    }

    protected function processingUrl(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value, mixed $attributes) {
                $source = $this->originalViewUrl ?? $this->originalDownloadUrl;

                if (! $source) {
                    return null;
                }

                // Generate a presigned URL directly (no caching) to avoid stale links
                $ttlSeconds = (int) (config('files.processing_url_ttl', 3600));
                $minutes = (int) max(1, ceil($ttlSeconds / 60));

                return presigned_url($source, $minutes);
            }
        );
    }

    protected function thumbnail(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, mixed $attributes) => $value ? presigned_url($value) : null
        );
    }

    protected function originalThumbnail(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, mixed $attributes) => $this->attributes['thumbnail']
        );
    }

    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, mixed $attributes) => FunctionsType::getType($attributes['type'] ?? $value)?->value ?? $value,
            set: fn (mixed $value) => $this->generateLowerString($value)
        );
    }

    protected function mimeType(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => $this->generateLowerString($value),
            set: fn (mixed $value) => $this->generateLowerString($value),
        );
    }

    protected function extension(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => $this->generateLowerString($value),
            set: fn (mixed $value) => $this->generateLowerString($value)
        );
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $this->sanitizeAndLimitString($value)
        );
    }

    protected function slug(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $this->generateSlug($value)
        );
    }

    protected function getUniqueNames(HasMany|HasOne $relation): array
    {
        $items = collect();

        if ($relation instanceof HasMany) {
            $items = $relation->get();
        }

        if ($relation instanceof HasOne) {
            $items = $relation->pluck('items');
        }

        return $items
            ->flatten(1)
            ->pluck('name')
            ->unique()
            ->values()
            ->toArray();
    }

    protected function getReducedSearchableArray(array $data, int $maxSizeInBytes): array
    {
        $aiFields = ['labels', 'texts', 'transcribes', 'moderations'];

        foreach ($aiFields as $field) {
            $data = $this->reduceFieldData($data, $field, $maxSizeInBytes);
        }

        return $data;
    }

    protected function reduceFieldData(array $data, string $field, int $maxSizeInBytes): array
    {
        if (isset($data[$field]) && is_array($data[$field])) {
            $encoded = json_encode($data);

            if (strlen($encoded) <= $maxSizeInBytes) {
                return $data;
            }

            while (strlen(json_encode($data)) > $maxSizeInBytes && count($data[$field]) > 0) {
                array_pop($data[$field]);
            }
        }

        if (strlen(json_encode($data)) > $maxSizeInBytes) {
            unset($data[$field]);
        }

        return $data;
    }

    public function scopeDuplicates(Builder $query)
    {
        return $query
            ->select('files.md5 as files_md5', DB::raw('COUNT(id) AS duplicate_count'))
            ->whereNotNull('md5')
            ->groupBy('md5')
            ->havingRaw('COUNT(id) > 1');
    }

    public function paidAdCreatives(): HasMany
    {
        return $this->hasMany(PaidAdCreative::class, 'remote_identifier', 'remote_service_file_id');
    }

    public static function countByType(?string $teamId = null): array
    {
        $query = DB::table((new self)->getTable())
            ->selectRaw('COUNT(*) AS total_files')
            ->when(filled($teamId), fn ($query) => $query->where('team_id', $teamId))
            ->whereNull('parent_id')
            ->whereNull('deleted_at');

        foreach (AssetType::cases() as $assetType) {
            $query->selectRaw(
                "SUM(CASE WHEN files.type = ? THEN 1 ELSE 0 END) AS total_{$assetType->value}s",
                [$assetType->value]
            );
        }

        $row = $query->first();

        return (array) $row;
    }

    public static function countDuplicates(?string $teamId = null): array
    {
        // Get duplicate counts per md5 and type, then aggregate totals per type
        $rows = DB::table((new self)->getTable())
            ->select(['type', 'md5', DB::raw('COUNT(*) - 1 AS duplicate_count')])
            ->when(filled($teamId), fn ($query) => $query->where('team_id', $teamId))
            ->whereNotNull('md5')
            ->whereNull('parent_id')
            ->whereNull('deleted_at')
            ->groupBy('md5', 'type')
            ->havingRaw('COUNT(*) - 1 > 0')
            ->get();

        $totals = $rows
            ->groupBy('type')
            ->map(fn ($group) => (int) $group->sum('duplicate_count'))
            ->all();

        $result = [];

        foreach ($totals as $type => $count) {
            $result["duplicate_{$type}s"] = $count;
        }

        return $result;
    }

    public static function topServices(?string $teamId = null): array
    {
        $results = self::query()
            ->whereHas('service', fn ($query) => $query->whereNot('status', IntegrationStatus::ARCHIVED))
            ->when(filled($teamId), fn ($query) => $query->where('team_id', $teamId))
            ->whereNull('parent_id')
            ->with([
                'service:id,name,email,photo,interface_type,custom_name',
                'user:id,profile_photo_path,name',
            ])
            ->select([
                'service_id',
                'user_id',
                DB::raw('COUNT(*) AS number_of_assets, SUM(size) AS group_file_size_sum'),
            ])
            ->groupBy(['service_id', 'user_id'])
            ->orderByDesc('group_file_size_sum')
            ->take(5)
            ->get();

        return [
            'top_services' => $results->map(fn (self $file) => [
                ...$file->toArray(),
                'custom_name'       => data_get($file, 'service.custom_name') ?? $file->service?->custom_name,
                'service_name'      => data_get($file, 'service.name') ?? $file->service?->name,
                'service_email'     => data_get($file, 'service.email') ?? $file->service?->email,
                'service_photo_url' => data_get($file, 'service.photo') ?? $file->service?->photo,
            ]),
            'total_size' => self::sum('size'),
        ];
    }

    public function scopeSimilarFiles(Builder $query): Builder
    {
        $hasSha256 = filled($this->sha256);
        $hasMd5 = filled($this->md5);

        if (! ($hasSha256 || $hasMd5)) {
            // No hashes to compare;
            // ensure we don't accidentally return unrelated records
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->when($hasSha256, fn ($q) => $q->where('sha256', $this->sha256))
            ->when($hasMd5, fn ($q) => $q->orWhere('md5', $this->md5))
            ->whereNot('id', $this->id);
    }

    public function abortIfDuplicateByHashes(): bool
    {
        if (! (filled($this->md5) || filled($this->sha256))) {
            return false;
        }

        $duplicate = self::query()
            ->where(function ($q) {
                $q->when(filled($this->sha256), fn ($q) => $q->where('sha256', $this->sha256))
                    ->when(filled($this->md5), fn ($q) => $q->orWhere('md5', $this->md5));
            })
            ->whereNot('id', $this->id)
            ->whereNotNull('download_url')
            ->latest('updated_at')
            ->first();

        if (! $duplicate) {
            return false;
        }

        // Replicate URLs from the original (duplicate) record
        $this->update([
            'is_duplicate' => true,
            'download_url' => $duplicate->originalDownloadUrl,
            'view_url'     => $duplicate->originalViewUrl,
            'thumbnail'    => $duplicate->originalThumbnail,
        ]);

        // Always mark the download as successful when we can reuse the original asset
        if (filled($duplicate->originalDownloadUrl)) {
            $this->markSuccess(
                FileOperationName::DOWNLOAD,
                'Duplicate by hash: reused original download_url',
                ['duplicate_file_id' => $duplicate->id]
            );
        }

        // If the original has a view_url, also mark convert as successful
        if (filled($duplicate->originalViewUrl)) {
            $this->markSuccess(
                FileOperationName::CONVERT,
                'Duplicate by hash: reused original view_url',
                ['duplicate_file_id' => $duplicate->id]
            );
        }

        return true;
    }

    public function gcsPath(?string $path = null): string
    {
        $path = $path ?? $this->gcsDownloadUrl();

        return 'gs://' . config('filesystems.disks.gcs.bucket') . '/' . $path;
    }

    public function gcsDownloadUrl(): string
    {
        return ltrim($this->originalViewUrl, '/');
    }

    public function serviceDirectoryTrees(): BelongsToMany
    {
        return $this->belongsToMany(ServiceDirectoryTree::class, 'file_service_directory_trees');
    }
}
