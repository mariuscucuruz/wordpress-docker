<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\WebSweep\Jobs;

use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Enums\MetaType;
use Illuminate\Support\Str;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use MariusCucuruz\DAMImporter\Jobs\Sync\PostMassCreateJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use MariusCucuruz\DAMImporter\Traits\FileInformation;
use MariusCucuruz\DAMImporter\Integrations\WebSweep\Models\WebSweepCrawlItem;

class SyncCrawledItemsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable,
        FileInformation,
        InteractsWithQueue,
        Loggable,
        Queueable,
        SerializesModels;

    public int $uniqueFor = 300;

    public int $timeout = 30;

    public int $tries = 1;

    /** @var array<int, string> */
    public array $crawlItemIds;

    public function __construct(array $crawlItemIds, public string $importGroupId)
    {
        $this->crawlItemIds = $crawlItemIds;

        $this->onQueue(QueueRouter::route('sync'));
    }

    public function handle(): void
    {
        $crawlItems = WebSweepCrawlItem::query()
            ->whereIn('id', $this->crawlItemIds)
            ->whereNotNull('url')
            ->where('url', '!=', '')
            ->where('should_import', true)
            ->with(['service', 'apifyRun'])
            ->get();

        if ($crawlItems->isEmpty()) {
            return;
        }

        $service = $crawlItems->first()->service;

        if (! $service) {
            return;
        }

        $now = now()->toDateTimeString();

        $existingFilesIdMap = DB::table('files')
            ->where('service_id', $service->id)
            ->whereIn('remote_service_file_id', $crawlItems->pluck('id')->all())
            ->pluck('id', 'remote_service_file_id')
            ->toArray();

        $filesToInsert = [];
        $metasToUpsert = [];
        $newSyncedFilesPayload = [];

        foreach ($crawlItems as $crawlItem) {
            $remoteId = (string) $crawlItem->id;

            if (isset($existingFilesIdMap[$remoteId])) {
                continue;
            }

            $fileId = (string) Str::uuid();

            $filesToInsert[] = [
                'id'                     => $fileId,
                'remote_service_file_id' => $remoteId,
                'service_id'             => $service->id,
                'import_group'           => $this->importGroupId,
                'user_id'                => $service->user_id,
                'team_id'                => $service->team_id,
                'service_name'           => $service->name,
                'name'                   => $crawlItem->file_name,
                'slug'                   => str($crawlItem->file_id)->slug()->toString(),
                'type'                   => strtolower((string) $crawlItem->type),
                'extension'              => strtolower((string) $crawlItem->file_extension),
                'mime_type'              => $this->getMimeTypeOrExtension($crawlItem->file_extension),
                'created_at'             => $now,
                'updated_at'             => optional($crawlItem->updated_at)->toDateTimeString() ?? $now,
            ];

            $metasToUpsert[] = [
                'metable_id'   => $fileId,
                'metable_type' => File::class,
                'key'          => MetaType::extra->value,
                'value'        => json_encode([
                    'group'       => $service->customName ?? $service->name,
                    'caption'     => $crawlItem->text,
                    'source_link' => $crawlItem->url,
                    'media_url'   => $crawlItem->from_url,
                    'media_type'  => $crawlItem->type,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $newSyncedFilesPayload[] = [
                'id'                     => $fileId,
                'remote_service_file_id' => $remoteId,
                'updated_at'             => $now,
            ];
        }

        if (! empty($filesToInsert)) {
            DB::transaction(function () use ($filesToInsert, $metasToUpsert, $crawlItems, $now) {
                DB::table('files')->insertOrIgnore($filesToInsert);

                if (! empty($metasToUpsert)) {
                    DB::table('metas')->upsert(
                        $metasToUpsert,
                        uniqueBy: ['metable_type', 'metable_id', 'key'],
                        update: ['value', 'updated_at']
                    );
                }

                DB::table('apify_crawl_items')
                    ->whereIn('id', $crawlItems->pluck('id')->all())
                    ->update([
                        'imported_at'   => $now,
                        'should_import' => false,
                    ]);
            });

            dispatch(new PostMassCreateJob($service->id, $newSyncedFilesPayload))
                ->afterCommit();
        }

        DB::table('apify_crawl_items')
            ->whereIn('id', $crawlItems->pluck('id')->all())
            ->update([
                'imported_at'   => $now,
                'should_import' => false,
            ]);
    }

    public function uniqueId(): string
    {
        return class_basename($this) . '_' . md5($this->importGroupId . '|' . implode(',', $this->crawlItemIds));
    }

    public function failed(Throwable $exception): void
    {
        $this->log('Failed to import crawled items batch', 'error', null, [
            'crawlItemIds'  => $this->crawlItemIds,
            'importGroupId' => $this->importGroupId,
            'error'         => $exception->getMessage(),
            'trace'         => $exception->getTrace(),
        ]);
    }
}
