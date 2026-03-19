<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\AdobeExperienceManager;

use Exception;
use Firebase\JWT\JWT;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use MariusCucuruz\DAMImporter\DTOs\FilePropertyResultDTO;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use MariusCucuruz\DAMImporter\Interfaces\HasRemoteServiceId;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\SourcePackageManager;

class AdobeExperienceManager extends SourcePackageManager implements HasFolders, HasMetadata, HasRemoteServiceId
{
    public ?string $queryBaseUrl;

    public ?string $clientId;

    public ?string $clientSecret;

    public ?string $technicalAccountId;

    public ?string $orgId;

    public ?string $privateKey;

    public ?string $metaScopes;

    public ?string $ims;

    public function initialize($settings = null): void
    {
        $settings = $settings ?? $this->getSettings();

        $this->queryBaseUrl = data_get($settings, 'queryBaseUrl');
        $this->clientId = data_get($settings, 'clientId');
        $this->clientSecret = data_get($settings, 'clientSecret');
        $this->technicalAccountId = data_get($settings, 'technicalAccountId');
        $this->orgId = data_get($settings, 'orgId');
        $this->privateKey = data_get($settings, 'privateKey');
        $this->metaScopes = data_get($settings, 'metaScopes');
        $this->ims = data_get($settings, 'ims');
        $this->ims = Str::start($this->ims ?? '', 'https://');

        $this->validateSettings();
    }

    public function validateSettings(): bool
    {
        throw_if(empty($this->clientId), InvalidSettingValue::make('Client ID'), 'Client ID is missing!');
        throw_if(empty($this->clientSecret), InvalidSettingValue::make('Client Secret'), 'Client Secret is missing!');
        throw_if(empty($this->technicalAccountId), InvalidSettingValue::make('Technical Account ID'), 'Technical Account ID is missing!');
        throw_if(empty($this->orgId), InvalidSettingValue::make('Org ID'), 'Org ID is missing!');
        throw_if(empty($this->privateKey), InvalidSettingValue::make('Private Key'), 'Private Key is missing!');
        throw_if(empty($this->metaScopes), InvalidSettingValue::make('Meta Scopes'), 'Meta Scopes are missing!');

        throw_if(filter_var($this->queryBaseUrl, FILTER_VALIDATE_URL) === false, InvalidSettingValue::make('Query Base URL'), 'Query Base URL is not a valid URL!');
        throw_if(filter_var($this->ims, FILTER_VALIDATE_URL) === false, InvalidSettingValue::make('IMS'), 'IMS is not a valid URL!');

        return true;
    }

    public function getSettings($customKeys = null): array
    {
        $settings = parent::getSettings($customKeys);

        $queryBaseUrl = $settings['ADOBE_EXPERIENCE_MANAGER_QUERY_BASE_URL'] ?? config('adobeexperiencemanager.query_base_url');
        $clientId = $settings['clientId'] ?? config('adobeexperiencemanager.client_id');
        $clientSecret = $settings['clientSecret'] ?? config('adobeexperiencemanager.client_secret');
        $technicalAccountId = $settings['technicalAccountId'] ?? config('adobeexperiencemanager.technical_account_id');
        $orgId = $settings['orgId'] ?? config('adobeexperiencemanager.org_id');
        $privateKey = $settings['privateKey'] ?? config('adobeexperiencemanager.private_key');
        $metaScopes = $settings['metascopes'] ?? config('adobeexperiencemanager.metascopes');
        $ims = $settings['ims'] ?? config('adobeexperiencemanager.ims');

        return compact('queryBaseUrl', 'clientId', 'clientSecret', 'technicalAccountId', 'orgId', 'privateKey', 'metaScopes', 'ims');
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $authUrl = config('adobeexperiencemanager.redirect_url') ?? config('app.url') . '/adobeexperiencemanager-redirect';

        if (isset($settings) && $settings->count()) {
            $authUrl .= '/'
                . '?state='
                . json_encode(['settings' => $this->settings?->pluck('id')?->toArray()]);
        }

        $this->redirectTo($authUrl);
    }

    public function getUser(): ?UserDTO
    {
        // No user endpoint available.
        $settingUser = $this->settings->load('user')->first()?->user;

        return new UserDTO([
            'email' => $settingUser ? $settingUser->email : $this->settings->firstWhere('name', 'ADOBE_EXPERIENCE_MANAGER_TECHNICAL_ACCOUNT_ID')?->payload,
            'name'  => $settingUser?->name,
        ]);
    }

    public function getRemoteServiceId(): string
    {
        return $this->technicalAccountId . $this->queryBaseUrl;
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        $jwtPayload = [
            'exp'                                => time() + (60 * 60),
            'iss'                                => $this->orgId,
            'sub'                                => $this->technicalAccountId,
            'aud'                                => "{$this->ims}/c/{$this->clientId}",
            "{$this->ims}/s/{$this->metaScopes}" => true,
        ];

        try {
            $jwt = JWT::encode($jwtPayload, $this->privateKey, 'RS256');

            $response = $this->http(false)
                ->asForm()
                ->post("{$this->ims}/ims/exchange/jwt", [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'jwt_token'     => $jwt,
                ])
                ->throw();

            $data = $response->collect();

            throw_if(empty(data_get($data, 'access_token')), CouldNotGetToken::class);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }

        return new TokenDTO([
            'access_token' => data_get($data, 'access_token'),
            'expires'      => data_get($data, 'expires_in')
                ? now()->addSeconds(data_get($data, 'expires_in'))->getTimestamp()
                : null,
            'created' => now(),
        ]);
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        $downloadUrl = $file->getMetaExtra('download_url');
        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'No download url present.');

        $file->load('service');

        try {
            $response = $this->http()->get($downloadUrl)->throw();

            $path = $this->storeDataAsFile($response->body(), $this->prepareFileName($file));
            throw_unless($path, CouldNotDownloadFile::class, 'Failed to initialize file storage');
        } catch (Exception $e) {
            $this->log("Error downloading file. File Id: {$file->id}. Error: {$e->getMessage()}", 'error');

            return false;
        }

        $file->update(['size' => $this->getFileSize($path)]);

        return $path;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        $downloadUrl = $file->getMetaExtra('download_url');
        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'No download url present.');

        return $this->handleMultipartDownload($file, $downloadUrl);
    }

    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        $downloadUrl = $file->getMetaExtra('download_url');
        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'No download url present.');

        return $this->handleDownloadFromService($file, $downloadUrl);
    }

    public function uploadThumbnail(mixed $file): ?string
    {
        $url = data_get($file, 'links.3.href') ?? data_get($file, 'thumbnail');

        if (empty($url)) {
            return null;
        }

        try {
            $response = $this->http()->get($url)->throw();

            $thumbnailPath = Path::join(config('manager.directory.thumbnails'), str()->random(6), str()->random(6)) . '.jpg';
            $this->storage::put($thumbnailPath, $response->body());

            return $thumbnailPath;
        } catch (Exception $e) {
            $this->log($e->getMessage());

            return null;
        }
    }

    public function uniqueFileId($attribute, $key = 'properties.jcr:uuid'): mixed
    {
        return data_get($attribute, $key)
            ?? md5(data_get($attribute, 'properties.name') . ($this->service?->id ?? ''));
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $fileId = $this->uniqueFileId($file);
        $fileName = data_get($file, 'properties.name');
        $extension = $this->getFileExtensionFromFileName($fileName);
        $type = $this->getFileTypeFromExtension($extension);
        $mimeType = $this->getMimeTypeOrExtension($extension) ?: Path::join($type, strtolower($extension));
        $thumbnailPath = data_get($file, 'links.3.href');

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => $fileId,
            'name'                   => $fileName,
            'thumbnail'              => $thumbnailPath,
            'mime_type'              => $mimeType,
            'type'                   => $type,
            'extension'              => $extension,
            'slug'                   => str()->slug(pathinfo($fileName, PATHINFO_FILENAME)),
            'created_time'           => data_get($file, 'properties.jcr:created')
                ? Carbon::parse(data_get($file, 'properties.jcr:created'))->format('Y-m-d H:i:s')
                : null,
            'modified_time' => null,
            //            'service_directory_tree_id' => data_get($file, 'properties.service_directory_tree_id'),
            // Todo: investigate incorrect modified time
            // 'modified_time' => data_get($file, 'properties.jcr:content.jcr:lastModified') ? Carbon::parse(data_get($file, 'properties.jcr:content.jcr:lastModified'))->format('Y-m-d H:i:s') : null,
        ]);
    }

    public function listFolderContent(?array $request): iterable
    {
        $folders = collect();
        $offset = 0;

        while (true) {
            if (! $response = collect($this->getFolderPageItems(data_get($request, 'folder_id'), $offset))) {
                return [];
            }

            $items = collect(data_get($response, 'items', []));
            $newFolders = $items->filter(fn ($item) => Str::afterLast(data_get($item, 'class.0'), '/') === 'folder');
            $folders = $folders->merge($newFolders);

            if ((int) data_get($response, 'offset', 0) >= (int) data_get($response, 'total', 0)) {
                break;
            }

            $offset = (int) data_get($response, 'offset', 0) + config('adobeexperiencemanager.limit', 200);
        }

        return $folders->map(fn ($folder) => [
            'id'    => data_get($folder, 'links.0.href'),
            'isDir' => true,
            'name'  => data_get($folder, 'properties.name'),
        ])->values();
    }

    public function getMetadataAttributes(\Illuminate\Support\Collection|array|null $properties): array
    {
        if (empty($properties)) {
            return [];
        }

        if (is_array($properties)) {
            $properties = collect($properties);
        }

        $properties = $properties->collect();

        $downloadUrl = data_get($properties, 'links.2.href');

        $metadata = $properties->only('properties')->toArray();
        $metadata['properties']['download_url'] = $downloadUrl;
        Arr::forget($metadata, 'properties.srn:paging');

        return $metadata['properties'] ?? [];
    }

    public function http($withToken = true): PendingRequest
    {
        $result = Http::maxRedirects(10)
            ->timeout(config('queue.timeout'))
            ->withUserAgent('Medialake AEM API Client/1.0')
            ->asJson()
            ->retry(3, 750, function (Exception $e, PendingRequest $request) use ($withToken) {
                if (! $withToken) {
                    return true;
                }

                if (method_exists($e, 'getResponse') && $e->getResponse()?->getStatusCode() === 404) {
                    return false;
                }

                if ($e->getCode() === 404) {
                    return false;
                }

                $this->log('Attempt to refresh AEM token after exception: ' . $e->getMessage());

                $this->service->update($this->getTokens()->toArray());
                $request->withToken($this->service->access_token);

                return true;
            });

        if ($withToken) {
            $result->withToken($this->service->access_token);
        }

        return $result;
    }

    public function testSettings(Collection $settings)
    {
        abort_if($settings->isEmpty(), 400, 'AEM settings are required');

        $settings = $settings
            ->filter(fn ($setting) => data_get($setting, 'type') !== 'file');

        abort_if(count($settings) !== $settings->count(), 406, 'All Settings must be present');

        return true;
    }

    public function getRemotePathFromRemoteUuid(string $uuid): ?string
    {
        $response = $this->http()
            ->withQueryParameters([
                'type'             => 'dam:Asset',
                '1_property'       => 'jcr:uuid',
                '1_property.value' => $uuid,
            ])->get($this->queryBaseUrl . '/bin/querybuilder.json');

        if ($response->failed()) {
            $this->log('Failed to get remote path for UUID: ' . $uuid, 'error');

            return null;
        }

        $url = data_get($response->json(), 'hits.0.path');

        if (empty($url)) {
            return null;
        }

        return Str::after($url, '/content/dam/');
    }

    public function getFileProperties(?string $path = null): FilePropertyResultDTO
    {
        if (empty($path)) {
            return new FilePropertyResultDTO(
                success: false,
                statusCode: null,
                message: 'Path is required'
            );
        }

        try {
            $fileResponse = $this->http()->get("{$this->queryBaseUrl}/api/assets/{$path}.json");
        } catch (Exception $e) {
            $this->log("Failed to fetch file properties for: {$path}. Error: {$e->getMessage()}", 'error');

            return new FilePropertyResultDTO(
                success: false,
                statusCode: null,
                message: $e->getMessage()
            );
        }

        if ($fileResponse->notFound()) {
            $this->log("File not found (404): {$path}. This file may have been deleted remotely.", 'warning');

            return new FilePropertyResultDTO(
                success: false,
                statusCode: 404,
                message: "File not found: {$path}"
            );
        }

        if ($fileResponse->failed()) {
            $this->log("Failed to fetch file properties for: {$path}", 'error');
            $this->log("Response status: {$fileResponse->status()}. Response body: {$fileResponse->body()}.", 'error');

            return new FilePropertyResultDTO(
                success: false,
                statusCode: $fileResponse->status(),
                message: Str::limit($fileResponse->body(), 200)
            );
        }

        $file = $fileResponse->json() ?? [];

        $metaDataResponse = $this->http()->get("{$this->queryBaseUrl}/content/dam/{$path}.3.json");

        if ($metaDataResponse->notFound()) {
            $this->log("Metadata not found (404) for: {$path}. Cannot sync file without remote UUID and metadata.", 'warning');

            return new FilePropertyResultDTO(
                success: false,
                statusCode: 404,
                message: "Metadata not found for: {$path}"
            );
        }

        if ($metaDataResponse->failed()) {
            $this->log("Failed to fetch metadata for: {$path}", 'error');
            $this->log("Response status: {$metaDataResponse->status()}. Response body: {$metaDataResponse->body()}.", 'error');

            return new FilePropertyResultDTO(
                success: false,
                statusCode: $metaDataResponse->status(),
                message: Str::limit($metaDataResponse->body(), 200)
            );
        }

        $file['properties'] = [
            ...$file['properties'] ?? [],
            ...$metaDataResponse->json(),
        ];

        return new FilePropertyResultDTO(
            success: true,
            statusCode: $fileResponse->status(),
            properties: $file
        );
    }

    public function getItemsFromRelativeIntervalResponse(string $path, ?string $relativeLowerBound = '-100y', mixed $offset = null): PromiseInterface|\Illuminate\Http\Client\Response
    {
        return $this->http()
            ->withQueryParameters([
                'type'                         => 'dam:Asset',
                'path'                         => "/content/dam/{$path}",
                'p.limit'                      => config('adobeexperiencemanager.limit', 200),
                'p.offset'                     => $offset,
                'relativedaterange.property'   => 'jcr:created',
                'relativedaterange.lowerBound' => $relativeLowerBound,
            ])
            ->timeout(60)
            ->get($this->queryBaseUrl . '/' . config('adobeexperiencemanager.delta_sync_url'));
    }

    public function getDeltaSyncFiles(string $path, ?string $interval = '-100y', mixed $offset = null): array
    {
        $interval ??= '-100y';
        $newFiles = [];

        try {
            $response = $this->getItemsFromRelativeIntervalResponse($path, $interval, $offset);
        } catch (Exception $e) {
            $this->log("Failed to get AEM Delta Sync Response {$e->getMessage()}", 'error');

            return [];
        }

        if ($response->failed()) {
            $this->log("Failed to get AEM Delta Sync Response. Code: {$response->status()}. Body: {$response->body()}", 'error');

            return [];
        }

        $offSet = data_get($response->json(), 'offset');
        $total = data_get($response->json(), 'total');
        $nextOffset = $this->getDeltaSyncNextOffset($offSet, $total);
        $results = data_get($response->json(), 'hits') ?? [];

        if (empty($results)) {
            $this->log('No results found for Delta Sync', 'warning');

            return compact('newFiles', 'nextOffset');
        }

        foreach ($results as $result) {
            $path = Str::afterLast(data_get($result, 'path'), '/content/dam/');
            $ext = Str::afterLast(data_get($result, 'path'), '.');

            if ($this->isExtensionSupported($ext) === false) {
                $this->log("Unsupported file extension: {$ext} for file: {$path}", 'warning');

                continue;
            }

            $fileData = $this->getFileProperties($path);

            if ($fileData === false) {
                $this->log("Failed to get file properties for: {$path}", 'error');

                continue;
            }

            $newFiles[] = $fileData;
        }

        if (empty($newFiles)) {
            $this->log('No new files found for Delta Sync', 'warning');

            return compact('newFiles', 'nextOffset');
        }

        $this->log('Delta Sync found ' . count($newFiles) . ' new files in AEM');

        return compact('newFiles', 'nextOffset');
    }

    public function getDeltaSyncNextOffset(?int $offset, ?int $total): int|false
    {
        if (is_null($offset) || is_null($total)) {
            return false;
        }

        if ($offset < $total) {
            return $offset + config('adobeexperiencemanager.limit', 200);
        }

        return false;
    }

    public function paginate(array $request = []): void
    {
        if (! $request) {
            $this->getFoldersAndDispatchSyncJob();

            return;
        }

        foreach (data_get($request, 'folder_ids', []) as $folderPath) {
            $this->getFoldersAndDispatchSyncJob($folderPath);
        }
    }

    public function getFolderPageItems($folderId = null, $offset = 0): array|bool
    {
        $url = match ($folderId) {
            null, 'root' => $this->queryBaseUrl . '/api/assets',
            default => $folderId,
        };

        try {
            $response = $this->http()
                ->withQueryParameters([
                    'limit'  => config('adobeexperiencemanager.limit', 200),
                    'offset' => $offset,
                ])
                ->get($url)
                ->throw();

            return [
                'items'       => data_get($response->collect(), 'entities', []),
                'offset'      => $offset,
                'total'       => data_get($response->collect(), 'properties.srn:paging.total'),
                'folder_name' => data_get($response->collect(), 'properties.name'),
            ];
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }
            $this->log($e->getMessage(), 'error');

            return false;
        }
    }

    /*
     * For each folder/sub folder we get their items and dispatch a job to sync the files
     */
    public function getFoldersAndDispatchSyncJob($folderId = null, $offset = 0, $metadata = null): void
    {
        $queue = [
            [
                'folderId' => $folderId,
                'offset'   => $offset,
            ],
        ];

        while (! empty($queue)) {
            $current = array_shift($queue);

            $folderId = $current['folderId'];
            $offset = (int) $current['offset'];
            $pageCount = 0;

            while (true) {
                // Slow API call
                if (! $response = $this->getFolderPageItems($folderId, $offset)) {
                    break;
                }

                if (! $items = data_get($response, 'items', false)) {
                    break;
                }

                $assets = [];

                foreach ($items as $item) {
                    $itemClass = data_get($item, 'class.0');
                    $type = Str::afterLast($itemClass, '/');
                    $name = data_get($item, 'properties.name');
                    $offTime = data_get($item, 'properties.offTime');

                    // Instead of recursive call, queue next folder
                    if ($type === 'folder') {
                        $child = data_get($item, 'links.0.href');

                        if ($child) {
                            $queue[] = [
                                'folderId' => $child,
                                'offset'   => 0, // NEW folder, always start at offset 0
                            ];
                        }
                    }

                    $ext = pathinfo($name, PATHINFO_EXTENSION);

                    if ($type === 'asset' && in_array(strtolower($ext), config('manager.meta.file_extensions'), true)) {
                        if (filled($offTime) && \Carbon\Carbon::parse($offTime)->isPast()) {
                            $this->log("Skipping file: {$name} because it was scheduled to go offline at " . \Carbon\Carbon::parse($offTime)->toDateTimeString());

                            continue;
                        }

                        $assets[] = $item;
                    }
                }

                $folderName = data_get($response, 'folder_name') ? data_get($response, 'folder_name') . '_' . $pageCount : 'root';

                if (! empty($assets)) {
                    // instead of calling massCreateOrUpdateFiles function directly here, we will rather
                    // push it to a queued jobs because we have to make two api requests to get extra properties
                    // for each file which is a slow process and this way we can run multiple jobs in parallel
                    $this->dispatchFilesSyncByFolder($folderName, $assets);
                }

                $offset = (int) data_get($response, 'offset', 0) + config('adobeexperiencemanager.limit', 200);

                if ($offset >= (int) data_get($response, 'total', 0)) {
                    break;
                }
                $pageCount++;
            }
        }
    }

    public function massCreateOrUpdateFiles($items, $folderName): void
    {
        $filteredFiles = [];

        foreach ($items as $item) {
            // slow / two api requests
            $url = data_get($item, 'links.0.href');
            $path = Str::between($url, '/api/assets/', '.json');

            if (empty($path)) {
                continue;
            }

            $result = $this->getFileProperties($path);

            // Handle both old array|bool and new FilePropertyResultDTO responses
            if (is_object($result) && method_exists($result, 'isNotFound')) {
                // New DTO response
                if (! $result->success) {
                    continue;
                }
                $filteredFiles[] = $result->properties;
            } elseif ($result !== false) {
                // Old array response (backward compatibility)
                $filteredFiles[] = $result;
            }
        }
        // todo: change the name from dispatch to something like massCreateOrUpdateFiles
        // not async / mass insert
        $this->dispatch($filteredFiles, $folderName);
    }
}
