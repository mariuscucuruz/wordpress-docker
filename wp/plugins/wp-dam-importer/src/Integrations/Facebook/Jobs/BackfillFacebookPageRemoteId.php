<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Facebook\Jobs;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use MariusCucuruz\DAMImporter\Enums\SourceIntegration;
use MariusCucuruz\DAMImporter\Integrations\Facebook\Facebook;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Symfony\Component\HttpFoundation\Response;

class BackfillFacebookPageRemoteId implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Loggable, Queueable;

    public $timeout = 43200;

    private Facebook $facebook;

    public function __construct(private readonly string $serviceId, private readonly string $remotePageId)
    {
        $this->onQueue(QueueRouter::route('db-query'));
    }

    public function uniqueId(): string
    {
        return "{$this->serviceId}_facebook_{$this->remotePageId}";
    }

    public function handle(): void
    {
        $service = Service::where('id', $this->serviceId)
            ->where('name', SourceIntegration::Facebook->value)
            ->firstOrFail();

        $this->facebook = $service->package;

        if (empty($this->facebook)) {
            $this->log('Could not initialize Facebook package for service: ' . $service->name, 'error');

            return;
        }

        $pageResponse = $this->facebook->getPage($this->remotePageId);

        if (empty($pageResponse)) {
            $this->log('Could not Facebook page information for remote page ID: ' . $this->remotePageId, 'error');

            return;
        }

        throw_unless(isset($pageResponse['id']) && isset($pageResponse['access_token']), new Exception('Facebook page missing data'));

        $batchUrl = $this->facebook->getBatchUrl($pageResponse);
        $pageId = data_get($pageResponse, 'id');

        if (empty($pageId)) {
            $this->log("No page ID found for Facebook Page: {$pageId}", 'warn');

            return;
        }

        $this->handleFacebookPage($batchUrl, $pageId);
    }

    public function handleFacebookPage(string $batchUrl, string $pageId): void
    {
        $this->log("Updating Page Id: {$pageId}");
        $nextUrl = null;

        do {
            try {
                $response = Http::timeout(config('queue.timeout'))
                    ->post($batchUrl)
                    ->throw();
            } catch (Exception $e) {
                $this->log("Error fetching files for Facebook Page ID: {$pageId}. Error: {$e->getMessage()}", 'error');

                break;
            }

            $batchedResponse = $response->json();
            collect($batchedResponse)
                ->filter(fn ($item) => data_get($item, 'code') === Response::HTTP_OK)
                ->each(function ($response) use ($pageId) {
                    $body = json_decode(data_get($response, 'body'), true);

                    $files = data_get($body, 'data') ?? [];
                    $filesWithChildren = $this->facebook->getAssetsWithChildren($files, $pageId);
                    $remoteFileIds = array_column($filesWithChildren, 'id');

                    if (filled($remoteFileIds)) {
                        $this->updateFiles($remoteFileIds, $pageId);
                    }

                    $nextUrl = data_get($body, 'paging.next');

                    if (filled($nextUrl)) {
                        $this->handleFacebookPaginatedAssets($nextUrl, $pageId);
                    }
                });
        } while (filled($nextUrl));
    }

    public function handleFacebookPaginatedAssets(string $url, string $pageId): void
    {
        try {
            $response = Http::timeout(config('queue.timeout'))
                ->get($url)
                ->throw();
        } catch (Exception $e) {
            $this->log("Error fetching files for Facebook Page ID: {$pageId}. Error: {$e->getMessage()}", 'error');

            return;
        }

        $body = $response->json();
        $files = data_get($body, 'data') ?? [];
        $filesWithChildren = $this->facebook->getAssetsWithChildren($files, $pageId);

        $remoteFileIds = array_column($filesWithChildren, 'id');

        if (filled($remoteFileIds)) {
            $this->updateFiles($remoteFileIds, $pageId);
        }

        $nextUrl = data_get($body, 'paging.next');

        if (filled($nextUrl)) {
            $this->handleFacebookPaginatedAssets($nextUrl, $pageId);
        }
    }

    public function updateFiles(array $fileIds, string $pageId): void
    {
        $n = File::query()
            ->where('service_name', SourceIntegration::Facebook->value)
            ->whereNull('remote_page_identifier')
            ->whereIn('remote_service_file_id', $fileIds)
            ->update(['remote_page_identifier' => $pageId]);

        if ($n > 0) {
            $this->log("Updated {$n} files with remote page ID: {$pageId}");
        }
    }
}
