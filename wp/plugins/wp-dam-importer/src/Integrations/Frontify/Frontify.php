<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Frontify;

use Throwable;
use JsonException;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use RuntimeException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use MariusCucuruz\DAMImporter\Enums\SettingsRequired;
use MariusCucuruz\DAMImporter\DTOs\IntegrationDefinition;
use MariusCucuruz\DAMImporter\MariusCucuruz\DAMImporter\Integrations\IsIntegration;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\RequestException;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use Illuminate\Http\Client\ConnectionException;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Integrations\Frontify\GraphQL\FrontifyQueries;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Interfaces\HasRemoteServiceId;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

class Frontify extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata, HasRemoteServiceId, HasSettings, IsIntegration, IsTestable
{
    public ?string $clientId;

    public ?string $clientSecret;

    public ?string $developerToken;

    public ?string $accessToken = null;

    public ?string $redirectUrl = null;

    public string $tenant;

    public ?SettingsRequired $credentialsType;

    public static function definition(): IntegrationDefinition
    {
        return IntegrationDefinition::make(
            name: self::getServiceName(),
            displayName: 'Frontify',
            providerClass: FrontifyServiceProvider::class,
            namespaceMap: [],
        );
    }

    /**
     * @throws CouldNotGetToken
     */
    public function initialize(): void
    {
        $this->credentialsType = SettingsRequired::tryFrom((string) $this->settings?->firstWhere('name', 'FRONTIFY_ACCOUNT_TYPE')?->payload) ?? SettingsRequired::TOKEN;
        $credentialRelatedSettingKey = config("frontify.api_setting_keys.{$this->credentialsType->value}");
        $this->customSettingKeys = array_keys(config("frontify.settings.{$credentialRelatedSettingKey}") ?? []);

        $settings = $this->getSettings($this->customSettingKeys);

        $this->clientId = data_get($settings, 'clientId');
        $this->clientSecret = data_get($settings, 'clientSecret');
        $this->developerToken = data_get($settings, 'developerToken');
        $this->tenant = data_get($settings, 'tenant');
        $this->redirectUrl = config('frontify.redirect_uri');

        if (empty($this->credentialsType)) {
            throw new RuntimeException('Frontify account type is missing.');
        }

        if (empty($this->tenant)) {
            throw new RuntimeException('Frontify tenant is missing.');
        }

        if (empty($this->redirectUrl)) {
            throw new RuntimeException('Frontify developer token is missing.');
        }

        if ($this->credentialsType === SettingsRequired::TOKEN) {
            if (empty($this->developerToken)) {
                throw new CouldNotGetToken('Frontify developer token is missing.');
            }
        }
    }

    public function getSettings($customKeys = null): array
    {
        $settings = parent::getSettings($customKeys);

        $clientId = data_get($settings, 'FRONTIFY_CLIENT_ID');
        $clientSecret = data_get($settings, 'FRONTIFY_CLIENT_SECRET');
        $tenant = data_get($settings, 'FRONTIFY_TENANT');
        $developerToken = data_get($settings, 'FRONTIFY_DEVELOPER_TOKEN');

        return match ($this->credentialsType) {
            SettingsRequired::TOKEN => compact('tenant', 'developerToken'),
            SettingsRequired::OAUTH => compact('clientId', 'clientSecret', 'tenant'),
            default                 => [],
        };
    }

    /**
     * @throws JsonException
     * @throws CouldNotGetToken
     */
    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        if ($this->redirectUrl === null) {
            $this->initialize();
        }

        $state = $this->generateRedirectOauthState();

        $authUrl = $this->redirectUrl . '?' . http_build_query(compact('state'));

        $this->redirectTo($authUrl);
    }

    /**
     * @throws Throwable
     */
    public function getTokens(array $tokens = []): TokenDTO
    {
        if ($this->credentialsType === null) {
            $this->initialize();
        }

        if ($this->credentialsType === SettingsRequired::TOKEN) {
            $this->accessToken = $this->developerToken;

            return new TokenDTO([
                'access_token' => $this->developerToken,
                'expires'      => null,
            ]);
        }

        throw new CouldNotGetToken('Frontify integration is misconfigured: OAuth2 is not yet implemented.');
    }

    public function getRemoteServiceId(): string
    {
        if ($this->credentialsType === SettingsRequired::TOKEN) {
            return $this->developerToken . $this->tenant;
        }

        throw new RuntimeException('Frontify integration is misconfigured: unknown credentials type.');
    }

    public function getUser(): ?UserDTO
    {
        try {
            if (empty($this->accessToken)) {
                $this->getTokens();
            }

            $response = $this->http()
                ->post('https://' . $this->tenant . '/graphql', [
                    'query' => FrontifyQueries::CURRENT_USER,
                ])
                ->throw();

            $body = $response->json();
            $email = data_get($body, 'data.currentUser.email');
            $name = data_get($body, 'data.currentUser.name');

            if (empty($email) && empty($name)) {
                return null;
            }

            return new UserDTO([
                'email' => $email,
                'name'  => $name,
            ]);
        } catch (Throwable $e) {
            $this->log('Frontify getUser failed: ' . $e->getMessage(), 'error');

            return null;
        }
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = data_get($request, 'folder_id') ?: 'root';

        try {
            if (empty($this->accessToken)) {
                $this->getTokens();
            }

            if ($folderId === 'root') {
                $response = $this->http()
                    ->post('https://' . $this->tenant . '/graphql', [
                        'query' => FrontifyQueries::BRANDS,
                    ])
                    ->throw();

                $brands = data_get($response->json(), 'data.brands', []);

                return collect($brands)->map(fn ($brand) => [
                    'id'    => 'brand:' . data_get($brand, 'id'),
                    'name'  => data_get($brand, 'name'),
                    'isDir' => true,
                ])->all();
            }

            if (Str::startsWith($folderId, 'brand:')) {
                $brandId = Str::after($folderId, 'brand:');
                $libraries = $this->getAllBrandLibraries($brandId);

                if ($libraries === []) {
                    return [];
                }

                return collect($libraries)->map(fn ($library) => [
                    'id'    => 'library:' . data_get($library, 'id'),
                    'name'  => data_get($library, 'name'),
                    'isDir' => true,
                ])->all();
            }

            return [];
        } catch (Throwable $e) {
            $this->log('Frontify listFolderContent failed: ' . $e->getMessage(), 'error');

            return [];
        }
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    protected function getAllBrandLibraries(string $brandId): array
    {
        $page = 1;
        $all = [];
        $total = null;

        do {
            $result = $this->requestBrandLibraries($brandId, $page);
            $libraries = $result['libraries'] ?? [];
            $total ??= (int) ($result['totalCount'] ?? 0);

            if (! empty($libraries)) {
                $all = array_merge($all, $libraries);
            }

            $page++;
        } while (! empty($libraries) && ($total === 0 || count($all) < $total));

        return $all;
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function requestBrandLibraries(string $brandId, int $page = 1): array
    {
        $limit = (int) config('frontify.libraries_pagination_limit', 100);

        $response = $this->http()
            ->post('https://' . $this->tenant . '/graphql', [
                'query'     => FrontifyQueries::BRAND_LIBRARIES,
                'variables' => [
                    'id'    => $brandId,
                    'limit' => $limit,
                    'page'  => $page,
                ],
            ])
            ->throw();

        $brand = data_get($response->json(), 'data.brand');

        $libraries = data_get($brand, 'libraries.items') ?? [];
        $total = (int) data_get($brand, 'libraries.total', 0);

        return [
            'totalCount' => $total,
            'libraries'  => $libraries,
        ];
    }

    public function paginate(array $request = []): void
    {
        $folders = data_get($request, 'metadata') ?? [];

        if (empty($folders)) {
            $this->log('No folders found for Frontify sync', 'error');

            return;
        }

        foreach ($folders as $folder) {
            $folderId = data_get($folder, 'folder_id');

            if (empty($folderId)) {
                continue;
            }

            $this->paginateFolder($folderId);
        }
    }

    public function paginateFolder(string $folderId): void
    {
        try {
            if (empty($this->accessToken)) {
                $this->getTokens();
            }

            if (Str::startsWith($folderId, 'brand:')) {
                $brandId = Str::after($folderId, 'brand:');

                $response = $this->http()
                    ->post('https://' . $this->tenant . '/graphql', [
                        'query'     => FrontifyQueries::BRAND_LIBRARIES,
                        'variables' => ['id' => $brandId],
                    ])
                    ->throw();

                $brand = data_get($response->json(), 'data.brand');

                if (! $brand) {
                    $this->log("Frontify paginateFolder: brand {$brandId} not found", 'warning');

                    return;
                }

                $libraries = data_get($brand, 'libraries.items', []);

                foreach ($libraries as $library) {
                    $libraryId = data_get($library, 'id');

                    if (! $libraryId) {
                        continue;
                    }

                    $this->paginateAndDispatchLibraryAssets($libraryId);
                }

                return;
            }

            if (Str::startsWith($folderId, 'library:')) {
                $libraryId = Str::after($folderId, 'library:');

                $this->paginateAndDispatchLibraryAssets($libraryId);

                return;
            }

            $this->log("Frontify paginateFolder: unsupported folder id '{$folderId}'", 'warning');
        } catch (Throwable $e) {
            $this->log('Frontify paginateFolder failed: ' . $e->getMessage(), 'error');
        }
    }

    protected function paginateAndDispatchLibraryAssets(string $libraryId): void
    {
        $index = config('frontify.pagination_start');

        try {
            do {
                $assetData = $this->requestAssets($libraryId, $index);
                $assets = data_get($assetData, 'assets') ?? [];

                $filteredAssets = $this->filterSupportedFileExtensions($assets);

                if (filled($filteredAssets)) {
                    $this->dispatch($filteredAssets, $index);
                }

                $index++;
            } while (filled($assets) && count($assets) > 0);
        } catch (Throwable $e) {
            $this->log('Frontify paginateLibrary failed: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function requestAssets(string $libraryId, int $page = 1): iterable
    {
        $response = $this->http()
            ->post('https://' . $this->tenant . '/graphql', [
                'query'     => FrontifyQueries::LIBRARY_ASSETS,
                'variables' => [
                    'id'    => $libraryId,
                    'limit' => config('frontify.pagination_limit', 100),
                    'page'  => $page,
                ],
            ])
            ->throw();

        $data = $response->json();
        $libraryData = data_get($data, 'data.library');

        $assets = data_get($libraryData, 'assets.items') ?? [];
        $total = (int) data_get($libraryData, 'assets.total', 0);

        return [
            'totalCount' => $total,
            'assets'     => $assets,
        ];
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $name = (string) data_get($file, 'filename');

        $extension = data_get($file, 'extension')
            ?: $this->getFileExtensionFromFileName($name);

        $mimeType = $this->getMimeTypeOrExtension($extension);

        $createdRaw = data_get($file, 'createdAt');
        $modifiedRaw = data_get($file, 'modifiedAt');

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
            'size'                   => data_get($file, 'size'),
            'name'                   => data_get($file, 'title'),
            'mime_type'              => $mimeType,
            'type'                   => data_get($file, '__typename'),
            'extension'              => $extension,
            'slug'                   => $name !== '' ? str()->slug($name) : null,
            'created_time'           => $toTime($createdRaw),
            'modified_time'          => $toTime($modifiedRaw),
        ]);
    }

    /**
     * @throws ConnectionException
     */
    public function getThumbnailPath(mixed $file = null, $source = null): string
    {
        $id = (string) data_get($file, 'id');

        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            $id,
            $id . '.jpg'
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

    /**
     * @throws Throwable
     */
    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $downloadUrl = $this->getDownloadUrl((string) $file->remote_service_file_id);

        throw_unless(
            $downloadUrl,
            CouldNotDownloadFile::class,
            "Download URL not provided. File ID: {$file->id}"
        );

        return $this->handleTemporaryDownload($file, $downloadUrl);
    }

    /**
     * @throws Throwable
     */
    // Both downloadTemporary and downloadMultiPart use the same chunking method to handle large files
    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        return $this->downloadTemporary($file, $rendition);
    }

    public function getDownloadUrl(string $fileId): ?string
    {
        try {
            if (empty($this->accessToken)) {
                $this->getTokens();
            }

            $response = $this->http()
                ->post('https://' . $this->tenant . '/graphql', [
                    'query'     => FrontifyQueries::ASSET_DOWNLOAD_URL,
                    'variables' => ['id' => $fileId],
                ])
                ->throw();

            $asset = data_get($response->json(), 'data.asset');
            $downloadUrl = data_get($asset, 'downloadUrl');

            if (filled($downloadUrl)) {
                return $downloadUrl;
            }

            return data_get($asset, 'previewUrl');
        } catch (Throwable $e) {
            $this->log('Frontify getDownloadUrl failed: ' . $e->getMessage(), 'error');

            return null;
        }
    }

    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        try {
            if (! filled($file->remote_service_file_id)) {
                throw new RuntimeException('Missing remote_service_file_id.');
            }

            $downloadUrl = $this->getDownloadUrl((string) $file->remote_service_file_id);

            if (empty($downloadUrl)) {
                throw new RuntimeException(
                    "Download URL not provided for remote file id {$file->remote_service_file_id}."
                );
            }

            $tempPath = $this->streamServiceFileToTempFile($downloadUrl);

            if (! is_file($tempPath) || filesize($tempPath) === 0) {
                throw new RuntimeException('Downloaded file from CDN is empty or missing.');
            }

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

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), ResponseAlias::HTTP_BAD_REQUEST, 'Frontify settings are required');

        $map = $settings->pluck('payload', 'name');

        $accountType = (string) ($map['FRONTIFY_ACCOUNT_TYPE'] ?? SettingsRequired::TOKEN->value);
        $this->credentialsType = SettingsRequired::tryFrom($accountType) ?? SettingsRequired::TOKEN;

        $credentialsRelatedSettingsKey = config('frontify.api_setting_keys.' . $this->credentialsType->value);
        $groupConfig = config("frontify.settings.{$credentialsRelatedSettingsKey}", []);

        if (! is_array($groupConfig)) {
            $groupConfig = [];
        }

        abort_if(
            empty($groupConfig),
            ResponseAlias::HTTP_BAD_REQUEST,
            'Frontify settings are not set in config'
        );

        $groupKeys = Arr::isAssoc($groupConfig)
            ? array_keys($groupConfig)
            : array_values($groupConfig);

        $required = array_merge(
            ['FRONTIFY_ACCOUNT_TYPE_SETTINGS'],
            $groupKeys
        );

        $provided = $settings->pluck('name')->unique()->values()->all();

        $missing = array_values(array_diff($required, $provided));
        $unknown = array_values(array_diff($provided, $required));

        abort_if(
            $missing !== [],
            ResponseAlias::HTTP_NOT_ACCEPTABLE,
            'Missing setting(s): ' . implode(', ', $missing)
        );

        abort_if(
            $unknown !== [],
            ResponseAlias::HTTP_NOT_ACCEPTABLE,
            'Unknown setting(s): ' . implode(', ', $unknown)
        );

        $clientId = (string) ($map['FRONTIFY_CLIENT_ID'] ?? '');
        $clientSecret = (string) ($map['FRONTIFY_CLIENT_SECRET'] ?? '');
        $tenant = (string) ($map['FRONTIFY_TENANT'] ?? '');
        $developerToken = (string) ($map['FRONTIFY_DEVELOPER_TOKEN'] ?? '');
        $accountType = (string) ($map['FRONTIFY_ACCOUNT_TYPE'] ?? SettingsRequired::TOKEN->value);

        $clientIdPattern = '/^[A-Za-z0-9._-]{8,64}$/';
        $clientSecretPattern = '/^\S{16,128}$/';
        $tenantPattern = '/^(?=.{1,253}$)(?!-)[A-Za-z0-9.-]+(?<!-)$/';
        $developerTokenPattern = '/^[A-Za-z0-9._-]{16,128}$/';

        $errors = [];

        if ($accountType === SettingsRequired::OAUTH->value) {
            if ($clientId === '' || ! preg_match($clientIdPattern, $clientId)) {
                $errors[] = 'FRONTIFY_CLIENT_ID';
            }

            if ($clientSecret === '' || ! preg_match($clientSecretPattern, $clientSecret)) {
                $errors[] = 'FRONTIFY_CLIENT_SECRET';
            }
        } else {
            if ($developerToken === '' || ! preg_match($developerTokenPattern, $developerToken)) {
                $errors[] = 'FRONTIFY_DEVELOPER_TOKEN';
            }
        }

        if ($tenant === '' || ! preg_match($tenantPattern, $tenant)) {
            $errors[] = 'FRONTIFY_TENANT';
        }

        abort_if(
            $errors !== [],
            ResponseAlias::HTTP_NOT_ACCEPTABLE,
            'Invalid setting(s): ' . implode(', ', $errors)
        );

        return true;
    }

    public function http($withAccessToken = true): PendingRequest
    {
        $refreshed = false;

        $request = Http::maxRedirects(10)
            ->timeout(config('queue.timeout'))
            ->withUserAgent(config('frontify.user_agent'))
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
                            $this->log('401 received; refreshing Frontify token and retrying once.');

                            if (isset($this->service)) {
                                $this->service->update($this->getTokens()->toArray());
                            }

                            $request->withToken($this->accessToken);
                            $refreshed = true;

                            return true;
                        }

                        if ($response->serverError() || $response->status() === 429) {
                            return true;
                        }

                        $this->log('Not retrying non-transient HTTP error: ' . $response->status());

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
            ->withUserAgent(config('frontify.user_agent'));
    }
}
