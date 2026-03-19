<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Aprimo;

use Exception;
use Throwable;
use RuntimeException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use MariusCucuruz\DAMImporter\DTOs\IntegrationDefinition;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\PackageTypes\IsSource;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;

class Aprimo extends IsSource implements CanPaginate, HasFolders, HasMetadata
{
    public string $clientId;

    public string $clientSecret;

    public string $tenant;

    public ?string $accessToken = null;

    public static function definition(): IntegrationDefinition
    {
        return IntegrationDefinition::make(
            name: self::getServiceName(),
            displayName: 'Aprimo',
            providerClass: AprimoServiceProvider::class,
            namespaceMap: [],
        );
    }

    public function initialize(): static
    {
        $settings = $this->getSettings();
        $configuration = self::loadConfiguration();

        $this->clientId = data_get($settings, 'APRIMO_CLIENT_ID') ?? data_get($configuration, 'client_id');
        $this->clientSecret = data_get($settings, 'APRIMO_CLIENT_SECRET') ?? data_get($configuration, 'client_secret');
        $this->tenant = data_get($settings, 'APRIMO_TENANT') ?? data_get($configuration, 'tenant');

        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->tenant)) {
            throw new InvalidSettingValue('Invalid Aprimo Client ID or Secret.');
        }

        $this->accessToken = $this->service->access_token ?? '';

        return $this;
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $state = $this->generateRedirectOauthState();
        $redirectUri = config('aprimo.redirect_uri');

        if (empty($redirectUri)) {
            abort(500, 'Aprimo redirect URI is not configured.');
        }

        $authUrl = $redirectUri . '?' . http_build_query(compact('state'));

        $this->redirectTo($authUrl);
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        $this->log('Getting new Aprimo access token...');

        try {
            $response = $this->http(false)->withHeaders([
                'Cache-Control' => 'no-cache',
            ])->asForm()->post('https://' . $this->tenant . '.aprimo.com/login/connect/token', [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => 'api',
                'grant_type'    => 'client_credentials',
            ])->throw();
        } catch (Throwable $e) {
            $this->log($e->getMessage(), 'error');

            $this->service?->update(['status' => IntegrationStatus::UNAUTHORIZED]);

            throw new CouldNotGetToken(
                "Tenant: {$this->tenant} - " . $e->getMessage()
            );
        }

        $body = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        throw_unless($body, CouldNotGetToken::class, 'Invalid token response.');
        throw_unless(isset($body['access_token']), CouldNotGetToken::class, 'Invalid token response.');

        $this->accessToken = data_get($body, 'access_token');
        $accessTokenTTLInSeconds = data_get($body, 'expires_in');

        return new TokenDTO([
            'access_token' => $this->accessToken,
            'expires'      => filled($accessTokenTTLInSeconds)
                ? now()->addSeconds($accessTokenTTLInSeconds)->toDateTimeString()
                : null,
        ]);
    }

    public function getUser(): ?UserDTO
    {
        $settingUser = $this->settings->load('user')->first()?->user;

        if (! isset($this->clientId, $this->clientSecret, $this->tenant)) {
            $this->initialize();
        }

        $material = implode('|', [$this->tenant, $this->clientId, $this->clientSecret]);

        $hash = substr(hash('sha256', $material), 0, 32);

        return new UserDTO([
            'email' => $hash,
            'name'  => $settingUser?->name,
        ]);
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = data_get($request, 'folder_id') ?: 'root';

        if ($folderId !== 'root') {
            return [];
        }

        $folders = config('aprimo.default_folders', []);

        return collect($folders)
            ->values()
            ->map(fn (array $f) => [
                'id'    => data_get($f, 'id'),
                'isDir' => true,
                'name'  => data_get($f, 'name'),
            ])
            ->filter(fn ($f) => filled($f['id']) && filled($f['name']))
            ->values()
            ->all();
    }

    public function paginate(array $request = []): void
    {
        $folders = data_get($request, 'metadata', []);

        if (! is_array($folders) || empty($folders)) {
            return;
        }

        $defaultFolders = config('aprimo.default_folders', []);

        foreach ($folders as $folder) {
            $folderId = trim(data_get($folder, 'folder_id', ''));

            if ($folderId === '') {
                $this->log('No folder_id provided in metadata item', 'error');

                continue;
            }

            if (array_key_exists($folderId, $defaultFolders)) {
                $searchExpression = trim(data_get($defaultFolders[$folderId], 'search_expression'));
            } else {
                $searchExpression = trim(data_get($folder, 'metadata.search_expression'));
            }

            if (empty($searchExpression)) {
                $this->log("No search expression for folder_id: {$folderId}", 'error');

                continue;
            }

            $this->getFoldersAndDispatchSyncJob($folderId, 1, $searchExpression);
        }
    }

    public function getFoldersAndDispatchSyncJob(
        $folderId = null,
        $offset = 1,
        $metadata = null
    ): void {
        if (empty($folderId)) {
            return;
        }

        $metadata = trim(($metadata ?? ''));

        if (empty($metadata)) {
            $this->log("Empty search expression for folder_id: {$folderId}", 'error');

            return;
        }

        $page = max(1, (int) ($offset ?: (int) config('aprimo.page_start', 1)));
        $pageSize = (int) config('aprimo.page_size');

        while (true) {
            $pageData = $this->getRecordIdsPageItems($page, $pageSize, $metadata);
            $items = (array) data_get($pageData, 'items', []);

            if (empty($items)) {
                break;
            }

            $batch = [];

            foreach ($items as $item) {
                $recordId = (string) data_get($item, 'id', '');
                $recordId = trim($recordId);

                if ($recordId !== '') {
                    $batch[] = ['id' => $recordId];
                }
            }

            if (! empty($batch)) {
                $this->dispatchFilesSyncByFolder((string) $folderId, $batch, 'page-' . $page);
            }

            $total = (int) data_get($pageData, 'totalCount', 0);

            if (($page * $pageSize) >= $total) {
                break;
            }

            $page++;
        }
    }

    private function getRecordIdsPageItems(int $page, int $pageSize, string $searchExpression): array
    {
        try {
            $url = "https://{$this->tenant}.dam.aprimo.com/api/core/search/records";

            $response = $this->http()
                ->withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->withQueryParameters([
                    'page'     => $page,
                    'pageSize' => $pageSize,
                ])
                ->post($url, [
                    'searchExpression' => [
                        'expression' => $searchExpression,
                    ],
                    'logRequest' => false,
                ]);

            if ($response->failed()) {
                $this->log("Aprimo record ID search failed on page {$page}: " . $response->body(), 'error');

                return [];
            }

            $body = $response->json() ?? [];

            return [
                'page'       => data_get($body, 'page', $page),
                'pageSize'   => data_get($body, 'pageSize', $pageSize),
                'totalCount' => data_get($body, 'totalCount', 0),
                'items'      => data_get($body, 'items', []),
            ];
        } catch (Throwable $e) {
            $this->log('Error fetching Aprimo record IDs: ' . $e->getMessage(), 'error');

            return [];
        }
    }

    public function massCreateOrUpdateFiles($items, $folderName): void
    {
        $ids = [];

        foreach ((array) $items as $item) {
            $id = is_array($item) ? (string) ($item['id'] ?? '') : (string) $item;
            $id = trim($id);

            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);

        if ($ids === []) {
            return;
        }

        $apiBatchSize = config('aprimo.api_batch_size');

        $records = [];

        foreach (array_chunk($ids, $apiBatchSize) as $batchIds) {
            $batchRecords = $this->getRecordsByIds($batchIds);

            if (! empty($batchRecords)) {
                $records = array_merge($records, $batchRecords);
            }
        }

        if (empty($records)) {
            return;
        }

        $records = $this->filterSupportedFileExtensions($records, 'masterFileLatestVersion.fileExtension');

        if ($records === []) {
            return;
        }

        $this->dispatch($records, $folderName);
    }

    private function getRecordsByIds(array $recordIds): array
    {
        $seen = [];
        $clean = [];

        foreach ($recordIds as $id) {
            $id = trim((string) $id);

            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $clean[] = $id;
        }

        if ($clean === []) {
            return [];
        }

        $url = "https://{$this->tenant}.dam.aprimo.com/api/core/search/records";

        $parts = array_fill(0, count($clean), 'id = ?');
        $expression = implode(' OR ', $parts);

        $response = $this->http()
            ->withHeaders([
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Select-Record' => 'masterfilelatestversion,thumbnail,fields',
            ])
            ->withQueryParameters([
                'page'     => 1,
                'pageSize' => count($clean),
            ])
            ->post($url, [
                'searchExpression' => [
                    'expression' => $expression,
                    'parameters' => $clean,
                ],
                'logRequest' => false,
            ]);

        if ($response->failed()) {
            $this->log('Aprimo bulk record search failed: ' . $response->status() . ' ' . $response->body(), 'error');

            return [];
        }

        $body = $response->json() ?? [];
        $items = ($body['items'] ?? []);

        $byId = [];

        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');

            if ($id !== '') {
                $byId[$id] = $item;
            }
        }

        $ordered = [];

        foreach ($clean as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $name = (string) data_get($file, 'masterFileLatestVersion.fileName', '');
        $extension = data_get($file, 'masterFileLatestVersion.fileExtension')
            ?: $this->getFileExtensionFromFileName($name);
        $type = $this->getFileTypeFromExtension($extension);
        $mimeType = $this->getMimeTypeOrExtension($extension);

        $createdRaw = data_get($file, 'masterFileLatestVersion.fileCreatedOn')
            ?? data_get($file, 'createdOn');
        $modifiedRaw = data_get($file, 'masterFileLatestVersion.fileModifiedOn')
            ?? data_get($file, 'modifiedOn');

        $toTime = static fn (?string $s) => $s
            ? Carbon::parse($s)->tz(config('app.timezone'))->format('Y-m-d H:i:s')
            : null;

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => data_get($file, 'id'),
            'size'                   => data_get($file, 'masterFileLatestVersion.fileSize'),
            'name'                   => $name !== '' ? pathinfo($name, PATHINFO_FILENAME) : null,
            'thumbnail'              => data_get($file, 'thumbnail.uri'),
            'mime_type'              => $mimeType,
            'type'                   => $type,
            'extension'              => $extension,
            'slug'                   => $name !== '' ? str()->slug($name) : null,
            'created_time'           => $toTime($createdRaw),
            'modified_time'          => $toTime($modifiedRaw),
        ]);
    }

    public function getMetadataAttributes(?array $properties): array
    {
        $items = data_get($properties, 'fields.items', []);

        $itemsByFieldName = collect($items)
            ->mapWithKeys(function (array $field) {
                $values = collect(data_get($field, 'localizedValues', []))
                    ->flatMap(function (array $loc) {
                        if (array_key_exists('values', $loc)) {
                            $v = $loc['values'];

                            if ($v === null) {
                                return [];
                            }

                            return is_array($v) ? $v : [$v];
                        }

                        if (array_key_exists('value', $loc) && $loc['value'] !== null) {
                            return [$loc['value']];
                        }

                        return [];
                    })
                    ->values()
                    ->all();

                $field['values'] = $values;

                return [
                    data_get($field, 'fieldName') => $field,
                ];
            })
            ->toArray();

        data_set($properties, 'fields.items', $itemsByFieldName);

        return $properties;
    }

    private function createOrderDownloadUrl(string $recordId, ?string $assetType = 'LatestVersionOfMasterFile'): string
    {
        $payload = [
            'type'                => 'download',
            'useCDN'              => 'Automatic',
            'disableNotification' => true,
            'disableProcessing'   => 'YesIfPermissionGranted',
            'singleFileZipMode'   => 'never',
            'targets'             => [[
                'recordId'    => $recordId,
                'targetTypes' => ['Document'],
                'assetType'   => $assetType ?? 'LatestVersionOfMasterFile',
            ]],
        ];

        $response = $this->http()
            ->withHeaders([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post("https://{$this->tenant}.dam.aprimo.com/api/core/orders", $payload);

        if ($response->failed()) {
            throw new RuntimeException("Order create failed: {$response->status()} {$response->body()}");
        }

        $order = $response->json();
        $status = strtolower((string) data_get($order, 'status', ''));
        $downloadUrl = data_get($order, 'deliveredFiles.0') ?? data_get($order, 'deliveredFiles.0.uri');

        if (! $downloadUrl) {
            throw new RuntimeException("No deliveredFiles returned (order status: {$status}).");
        }

        if ($status !== 'success') {
            throw new RuntimeException("Order not successful (status: {$status}).");
        }

        return $downloadUrl;
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $downloadUrl = $this->createOrderDownloadUrl((string) $file->remote_service_file_id);

        throw_unless(
            $downloadUrl,
            CouldNotDownloadFile::class,
            "Download URL not provided. File ID: {$file->id}"
        );

        return $this->handleTemporaryDownload($file, $downloadUrl);
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        $downloadUrl = $this->createOrderDownloadUrl((string) $file->remote_service_file_id);
        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'Unable to get download URL from Aprimo');

        return $this->commonDownloadMultiPart($file, $downloadUrl);
    }

    public function commonDownloadMultiPart(File $file, string $downloadUrl): ?string
    {
        $fileKey = $this->prepareFileName($file);
        throw_unless($fileKey, CouldNotDownloadFile::class, 'Cannot start multi-part upload: $fileKey missing');

        $uploadId = $this->createMultipartUpload($fileKey, $file->mime_type);
        throw_unless($uploadId, CouldNotDownloadFile::class, 'Failed to initialize multi-part upload');

        $chunkSize = config('manager.chunk_size', 5) * 1024 * 1024;
        $chunkStart = 0;
        $partNumber = 1;
        $parts = [];

        try {
            while (true) {
                $chunkEnd = $chunkStart + $chunkSize;

                $response = Http::timeout(config('queue.timeout'))
                    ->withHeaders(['Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd)])
                    ->get($downloadUrl)
                    ->throw();

                if ($response->status() !== Response::HTTP_PARTIAL_CONTENT) {
                    break;
                }

                $parts[] = $this->uploadPart($fileKey, $uploadId, $partNumber++, $response->body());

                $chunkStart = $chunkEnd + 1;
            }
        } catch (Exception $e) {
            if ($e->getCode() === Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE
                || ($e->getCode() === Response::HTTP_NOT_FOUND && $chunkStart > 0)
            ) {
                return $this->completeMultipartUpload($fileKey, $uploadId, $parts);
            }

            $this->log("Download multi-part failed: {$e->getMessage()}", 'error', null, $e->getTrace());

            return null;
        }

        return $this->completeMultipartUpload($fileKey, $uploadId, $parts);
    }

    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        try {
            if (! filled($file->remote_service_file_id)) {
                throw new RuntimeException('Missing remote_service_file_id.');
            }

            $downloadUrl = $this->createOrderDownloadUrl((string) $file->remote_service_file_id);

            $tempPath = $this->streamServiceFileToTempFile($downloadUrl);

            $filename = trim(
                ($file->name ?: (string) $file->id) . '.' . ltrim((string) $file->extension, '.'),
                '.'
            );

            $response = new BinaryFileResponse($tempPath, 200, [
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            ]);

            $response->setContentDisposition('attachment', $filename);
            $response->deleteFileAfterSend();

            return $response;
        } catch (Throwable $e) {
            $this->log("Error downloading file. Error: {$e->getMessage()}", 'error');

            return false;
        }
    }

    public function getThumbnailPath(mixed $file = null, $source = null): string
    {
        $id = data_get($file, 'id', str()->random(6));

        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            $id,
            str()->slug($id) . '.jpg'
        );

        if ($file) {
            $source = data_get($file, 'thumbnail');
        }

        if (! $source) {
            return '';
        }

        $this->storage->put($thumbnailPath, $this->httpCdn()->get($source)->body());

        return $thumbnailPath;
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Aprimo settings are required');

        $expected = config('aprimo.settings', []);

        if (! is_array($expected)) {
            $expected = [];
        }

        $required = Arr::isAssoc($expected) ? array_keys($expected) : array_values($expected);

        $provided = $settings->pluck('name')->unique()->values()->all();

        $missing = array_values(array_diff($required, $provided));
        $unknown = array_values(array_diff($provided, $required));

        abort_if($missing !== [], 406, 'Missing setting(s): ' . implode(', ', $missing));
        abort_if($unknown !== [], 406, 'Unknown setting(s): ' . implode(', ', $unknown));

        $map = $settings->pluck('payload', 'name');

        $clientId = (string) ($map['APRIMO_CLIENT_ID'] ?? '');
        $clientSecret = (string) ($map['APRIMO_CLIENT_SECRET'] ?? '');
        $tenant = (string) ($map['APRIMO_TENANT'] ?? '');

        $clientIdPattern = '/^[A-Za-z0-9._-]{8,64}$/';
        $clientSecretPattern = '/^\S{16,128}$/';
        $tenantPattern = '/^(?=.{1,63}$)(?!-)[A-Za-z0-9-]+(?<!-)$/';

        $errors = [];

        if ($clientId === '' || ! preg_match($clientIdPattern, $clientId)) {
            $errors[] = 'client_id';
        }

        if ($clientSecret === '' || ! preg_match($clientSecretPattern, $clientSecret)) {
            $errors[] = 'client_secret';
        }

        if ($tenant === '' || ! preg_match($tenantPattern, $tenant)) {
            $errors[] = 'tenant';
        }

        abort_if($errors !== [], 406, 'Invalid setting(s): ' . implode(', ', $errors));

        return true;
    }

    public function http($withAccessToken = true): PendingRequest
    {
        $refreshed = false;

        $request = Http::maxRedirects(10)
            ->withHeaders([
                'API-VERSION' => config('aprimo.api_version'),
            ])
            ->timeout(config('queue.timeout'))
            ->withUserAgent(config('aprimo.user_agent'))
            ->asJson()
            ->retry(
                3,
                750,
                function ($exception, PendingRequest $request) use ($withAccessToken, &$refreshed) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException && ($response = $exception->response)) {
                        if ($withAccessToken && $response->status() === 401 && ! $refreshed) {
                            $this->log('401 received; refreshing Aprimo token and retrying once.');
                            $this->service->update($this->getTokens()->toArray());
                            $request->withToken($this->service->access_token);
                            $refreshed = true;

                            return true;
                        }

                        if ($response->serverError() || $response->status() === 429) {
                            return true;
                        }

                        $this->log('Not retrying non-transient HTTP error: ' . $response->status() . $response->body());

                        return false;
                    }

                    return false;
                }
            );

        if ($withAccessToken && filled($this->accessToken)) {
            $request->withToken($this->accessToken);
        }

        return $request;
    }

    private function httpCdn(): PendingRequest
    {
        return Http::maxRedirects(10)
            ->timeout(config('queue.timeout'))
            ->withUserAgent(config('aprimo.user_agent'));
    }
}
