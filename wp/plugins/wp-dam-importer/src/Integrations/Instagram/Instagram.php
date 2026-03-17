<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Instagram;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasDateRangeFilter;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\SourcePackageManager;
use MariusCucuruz\DAMImporter\Integrations\Instagram\Traits\InstagramGraphApi;
use MariusCucuruz\DAMImporter\Integrations\Instagram\Enums\InstagramServiceType;
use MariusCucuruz\DAMImporter\Integrations\Instagram\Traits\InstagramBasicDisplayApi;

class Instagram extends SourcePackageManager implements CanPaginate, HasDateRangeFilter, HasFolders, HasMetadata
{
    use InstagramBasicDisplayApi, InstagramGraphApi;

    public Client $client;

    public bool $isGraphApi = false;

    private ?string $accessToken;

    private ?string $clientId;

    private ?string $clientSecret;

    private ?string $redirectUri;

    private ?string $configId;

    // Note: Any method here will likely require have a corresponding method in both InstagramGraph and InstagramBasicDisplay traits

    public function initialize(): void
    {
        $this->isGraphApi = $this->isGraphApi();
        $this->client = new Client;

        match ($this->isGraphApi) {
            true    => $this->initializeGraphApi(),
            default => $this->initializeBasicDisplay(),
        };
    }

    public function isGraphApi(): bool
    {
        $accountType = $this->settings?->firstWhere('name', 'INSTAGRAM_ACCOUNT_TYPE')?->payload;

        if (empty($accountType)) {
            return true; // ML default Oauth is graph api
        }

        return match (InstagramServiceType::tryFrom((string) $accountType)) {
            InstagramServiceType::PERSONAL => false,
            default                        => true,
        };
    }

    public function getSettings($customKeys = null): array
    {
        return match ($this->isGraphApi) {
            true    => $this->getSettingsGraphApi(),
            default => $this->getSettingsBasicDisplay(),
        };
    }

    public function testSettings(Collection $settings): bool
    {
        return match ($this->isGraphApi) {
            true    => $this->testSettingsGraphApi($settings),
            default => $this->testSettingsBasicDisplay($settings),
        };
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        return match ($this->isGraphApi) {
            true    => $this->getTokensGraphApi($tokens),
            default => $this->getTokensBasicDisplay($tokens),
        };
    }

    public function storeToken($body): array
    {
        return match ($this->isGraphApi) {
            true    => $this->storeTokenGraphApi($body),
            default => $this->storeTokenBasicDisplay($body),
        };
    }

    public function exchangeAccessTokenForLongLiveAccessToken($token): ?array
    {
        return match ($this->isGraphApi) {
            true    => $this->exchangeAccessTokenForLongLiveAccessTokenGraphApi($token),
            default => $this->exchangeAccessTokenForLongLiveAccessTokenBasicDisplay($token),
        };
    }

    public function getUser(): ?UserDTO
    {
        return match ($this->isGraphApi) {
            true    => $this->getUserGraphApi(),
            default => $this->getUserBasicDisplay(),
        };
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        match ($this->isGraphApi) {
            true    => $this->redirectToAuthUrlGraphApi($settings, $email),
            default => $this->redirectToAuthUrlBasicDisplay($settings, $email),
        };
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $this->handleTokenExpiration();

        $sourceLink = $file->getMetaExtra('source_link');

        if ($sourceLink && $path = $this->handleTemporaryDownload($file, $sourceLink)) {
            return $path;
        }

        if (! $downloadUrl = $this->getNewDownloadUrl($file)) {
            $this->log('Download URL Missing for file: ' . $file->id . '. Omitted if the Media contains copyrighted material, or has been flagged for a copyright violation.', 'error');
        }

        throw_unless(
            $downloadUrl,
            CouldNotDownloadFile::class,
            "Download unavailable. Instagram may have detected copyrighted material or the media may have been flagged for copyright violation. File ID: {$file->id}"
        );

        return $this->handleTemporaryDownload($file, $downloadUrl);
    }

    public function handleTemporaryDownload(File $file, ?string $downloadUrl = ''): string|bool
    {
        throw_unless(
            $downloadUrl,
            CouldNotDownloadFile::class,
            "Download URL Missing for file: {$file->id}"
        );

        // Download in 5 MB chunks:
        $chunkSizeBytes = config('manager.chunk_size', 5) * 1048576;
        $chunkStart = 0;
        $response = false;

        try {
            while (true) {
                $chunkEnd = $chunkStart + $chunkSizeBytes;
                $response = $this->client->request('GET', $downloadUrl, [
                    'headers' => [
                        'Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                    ],
                ]);

                if ($response->getStatusCode() !== Response::HTTP_PARTIAL_CONTENT) {
                    break;
                }

                $this->downstreamToTmpFile($response->getBody()->getContents());
                $chunkStart = $chunkEnd + 1;
            }
        } catch (GuzzleException|Exception $e) {
            // Handle partial content
            if (! $response) {
                $this->log("Error getting download link: {$e->getMessage()}", 'error');

                return false;
            }

            if ($response->getStatusCode() !== Response::HTTP_PARTIAL_CONTENT) {
                $this->log("Error getting download link: {$e->getMessage()}", 'error');

                return false;
            }

            $this->downstreamToTmpFile($response->getBody()->getContents());
            $this->log('File download completed');
        }

        $path = $this->downstreamToTmpFile(null, $this->prepareFileName($file));

        $file->update(['size' => $this->getFileSize($path)]);

        return $path;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $this->handleTokenExpiration();
        $sourceLink = $file->getMetaExtra('source_link');

        if ($sourceLink && $path = $this->handleMultipartDownload($file, $sourceLink)) {
            return $path;
        }

        if (! $downloadUrl = $this->getNewDownloadUrl($file)) {
            $this->log('Download URL Missing for file: ' . $file->id . '. Omitted if the Media contains copyrighted material, or has been flagged for a copyright violation.', 'error');
        }

        throw_unless(
            $downloadUrl,
            CouldNotDownloadFile::class,
            "Download unavailable. Instagram may have detected copyrighted material or the media may have been flagged for copyright violation. File ID: {$file->id}"
        );

        return $this->handleMultipartDownload($file, $downloadUrl);
    }

    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $this->handleTokenExpiration();
        $downloadUrl = $this->getNewDownloadUrl($file);

        throw_unless(
            $downloadUrl,
            CouldNotDownloadFile::class,
            "Failed to get download URL from Instagram. File ID: {$file->id}"
        );

        return $this->handleDownloadFromService($file, $downloadUrl);
    }

    public function getNewDownloadUrl(File $file): ?string
    {
        return match ($this->isGraphApi) {
            true    => $this->getNewDownloadUrlGraphApi($file),
            default => $this->getNewDownloadUrlBasicDisplay($file),
        };
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $thumbnailPath = $file['thumbnail_url'] ?? $file['media_url'] ?? null;

        $name = Carbon::parse($file['timestamp'])->format('Y-m-d H:i:s');

        if (isset($file['child_count'])) {
            $name = "{$name}({$file['child_count']})";
        }

        $commonFields = [
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'remote_service_file_id' => data_get($file, 'id'),
            'name'                   => $name,
            'thumbnail'              => $thumbnailPath,
            'slug'                   => str()->slug(Carbon::parse($file['timestamp'])->format('Y-m-d H:i:s')),
            'created_time'           => Carbon::parse($file['timestamp'])->format('Y-m-d H:i:s'),
            'remote_page_identifier' => data_get($file, 'page_id'),
            // media_url field is omitted from responses if the media contains copyrighted material or flagged for a copyright violation
        ];

        return match ($file['media_type']) {
            'VIDEO' => new FileDTO([
                ...$commonFields,
                ...[
                    'mime_type' => 'video/mp4',
                    'type'      => 'video',
                    'extension' => 'mp4',
                ],
            ]),
            // IMAGE OR CAROUSEL_ALBUM TYPE
            default => new FileDTO([
                ...$commonFields,
                ...[
                    'mime_type' => 'image/jpeg',
                    'type'      => 'image',
                    'extension' => 'jpg',
                ],
            ]),
        };
    }

    public function uploadThumbnail(mixed $file = null, $source = null): ?string
    {
        $source = $source ?? data_get($file, 'thumbnail');

        if (empty($source)) {
            return null;
        }

        $id = data_get($file, 'id') ?? str()->random(6);

        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            $id,
            str()->slug($id) . '.jpg'
        );

        try {
            $response = Http::timeout(config('queue.timeout'))->get($source)->throw();

            $this->storage->put($thumbnailPath, $response->body());

            return $thumbnailPath;
        } catch (Exception $e) {
            $this->log('Error getting thumbnail: ' . $e->getMessage(), 'error');

            return null;
        }
    }

    public function getItemChildren($item): array
    {
        $itemChildren = [];

        if ($children = data_get($item, 'children.data')) {
            foreach ($children as $index => $child) {
                // Original item is duplicated in children, ignore first child
                if ($index == 0) {
                    continue;
                }

                $id = data_get($child, 'id');
                $url = data_get($child, 'media_url');
                $type = data_get($child, 'media_type');
                $thumbnail = data_get($child, 'thumbnail_url');

                if ($id && $url) {
                    $childToAdd = $item;
                    unset($childToAdd['children']);

                    $childToAdd['id'] = $id;
                    $childToAdd['media_url'] = $url;
                    $childToAdd['child_count'] = $index;
                    $childToAdd['media_type'] = $type;
                    $childToAdd['page_id'] = $item['page_id'];

                    if ($thumbnail) {
                        $childToAdd['thumbnail_url'] = $thumbnail;
                    }

                    $itemChildren[] = $childToAdd;
                }
            }
        }

        return $itemChildren;
    }

    public function handleTokenExpiration(): void
    {
        match ($this->isGraphApi) {
            true    => $this->handleTokenExpirationGraphApi(),
            default => $this->handleTokenExpirationBasicDisplay()
        };
    }

    public function listFolderContent(?array $request): iterable
    {
        return match ($this->isGraphApi) {
            true    => $this->listFolderContentGraphApi($request),
            default => [], // BasicDisplay has no folders
        };
    }

    public function listFolderSubFolders(?array $request): iterable
    {
        return []; // No sub-folders
    }

    public function getMetadataAttributes(?array $properties): array
    {
        return collect($properties)
            ->filter(fn ($value, $key) => array_key_exists($key, config('instagram.metadata_fields', [])))
            ->mapWithKeys(function ($value, $key) {
                $formattedKey = data_get(config('instagram.metadata_fields', []), $key, $key); // Update key name here for consistency across both APIs

                return [$formattedKey => $value];
            })->toArray() + $properties;
    }

    /**
     * @throws \Throwable
     */
    public function paginate(array $request = []): void
    {
        match ($this->isGraphApi) {
            true    => $this->paginateGraphApi($request),
            default => $this->paginateBasicDisplay($request),
        };
    }

    /**
     * @throws \Throwable
     */
    public function getAllFiles(string $endpoint = '', $i = 1): void
    {
        match ($this->isGraphApi) {
            true    => $this->getFilesGraphApi(),
            default => $this->getAllFilesBasicDisplay($endpoint, $i),
        };
    }

    /**
     * used for mocking
     *
     * @throws GuzzleException
     */
    public function getResponse(string $endpoint, array $options)
    {
        return $this->client->get($endpoint, $options);
    }
}
