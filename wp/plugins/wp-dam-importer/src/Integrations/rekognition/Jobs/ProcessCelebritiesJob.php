<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Rekognition\Jobs;

use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Support\Arr;
use MariusCucuruz\DAMImporter\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use MariusCucuruz\DAMImporter\Traits\Loggable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class ProcessCelebritiesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable,
        InteractsWithQueue,
        Loggable,
        Queueable,
        SerializesModels;

    public $timeout = 3600;

    public $tries = 1;

    public function __construct(public File $file, public $items = [])
    {
        $this->onQueue(QueueRouter::route('api'));
    }

    public function handle(): void
    {
        $this->startLog();

        $this->log("Processing Celebrities for file ID: ({$this->file->id})");

        $this->file->load('rekognitionTasks');

        $urls = Arr::flatten(Arr::pluck($this->items, 'urls'));
        $urls = array_filter($urls, fn ($url) => filled($url));
        $wikiDataUrls = Arr::where($urls, fn ($url) => str_contains($url, 'wikidata'));
        $entityIds = array_map(function ($url) {
            preg_match('/Q\d+/', $url, $matches);

            return $matches[0] ?? null;
        }, $wikiDataUrls);

        $entityIds = array_values(array_filter($entityIds));
        $uniqueEntityIds = array_values(array_unique($entityIds));
        $entityIdsString = implode('|', $uniqueEntityIds);

        $response = Http::timeout(config('queue.timeout'))
            ->withHeaders([
                'User-Agent' => 'MedialakeBot/1.0 (' . config('app.url') . '/; ' . config('packager.author_email') . ')',
            ])
            ->get(config('rekognition.celebrity_image.api'), [
                'action'    => 'wbgetentities',
                'ids'       => $entityIdsString,
                'format'    => 'json',
                'props'     => 'claims|descriptions',
                'languages' => 'en',
            ]);

        if ($response->failed()) {
            $this->log(text: 'Wikidata API failure', level: 'error', context: [
                'status' => $response->status(),
                'body'   => $response->body(),
                'ids'    => $entityIdsString,
            ]);

            return;
        }

        $data = $response->json();

        $entitiesData = [];

        foreach ($entityIds as $id) {
            $description = $data['entities'][$id]['descriptions']['en']['value'] ?? 'No description available';
            $imageUrl = null;

            if (isset($data['entities'][$id]['claims']['P18'])) {
                $imageFileName = $data['entities'][$id]['claims']['P18'][0]['mainsnak']['datavalue']['value'];
                $imageFileName = implode('_', explode(' ', $imageFileName));
                $imageUrl = config('rekognition.celebrity_image.commons') . ':FilePath/' . urlencode($imageFileName);
            }
            $entitiesData[$id] = [
                'description' => $description,
                'imageUrl'    => $imageUrl,
                'entityId'    => $id,
            ];
        }

        foreach ($this->items as $item) {
            $data = [
                'rekognition_task_id' => $this->file->rekognitionTasks()->firstWhere('job_type', 'celebrities')?->id,
                'file_id'             => $this->file->id,
                'mime_type'           => $this->file->mime_type,
                'confidence'          => data_get($item, 'confidence', 100),
                'time'                => data_get($item, 'time', 0),
                'service_type'        => 'AWS',
                'service_name'        => config('rekognition.name'),
                'model_version'       => config('rekognition.version'),
                'name'                => data_get($item, 'name', ''),
                'bounding_box'        => data_get($item, 'boundingBox', ''),
            ];

            $entity = collect($entitiesData)->first(function ($entity) use ($item) {
                return collect($item['urls'] ?? [])
                    ->map(fn ($url) => strtolower(trim($url)))
                    ->contains(fn ($url) => str_contains($url, strtolower($entity['entityId'])));
            });

            $normalizedUrls = collect($item['urls'] ?? [])
                ->map(fn ($url) => str_starts_with($url, 'http') ? $url : 'https://' . $url)
                ->toArray();

            $data['instances'] = [
                'urls'        => $normalizedUrls,
                'knownGender' => data_get($item, 'knownGender', ''),
                'description' => data_get($entity, 'description', ''),
                'imageUrl'    => data_get($entity, 'imageUrl', ''),
            ];

            if (! empty($item['id']) && $existing = $this->file->celebrityDetections()->find($item['id'])) {
                $existing->update($data);
            } else {
                $this->file->celebrityDetections()->create($data);
            }
        }

        $this->endLog();
    }

    public function uniqueId(): string
    {
        return class_basename($this) . '_' . $this->file->id;
    }
}
