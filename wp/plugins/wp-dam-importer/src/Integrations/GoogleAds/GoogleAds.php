<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\GoogleAds;

use Exception;
use Throwable;
use Google\Client;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use Google\Auth\OAuth2;
use MariusCucuruz\DAMImporter\Jobs\PostToVulcanJob;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use Google\ApiCore\ApiException;
use MariusCucuruz\DAMImporter\DTOs\IntegrationDefinition;
use MariusCucuruz\DAMImporter\MariusCucuruz\DAMImporter\Integrations\IsIntegration;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Integrations\GoogleAds\Traits\Performance;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V20\GoogleAdsClient;
use Google\Service\Oauth2 as GoogleServiceOauth2;
use MariusCucuruz\DAMImporter\Interfaces\HasPerformance;
use Google\Ads\GoogleAds\V20\Services\GoogleAdsRow;
use MariusCucuruz\DAMImporter\Integrations\GoogleAds\Enum\GoogleAdObjectType;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Google\Ads\GoogleAds\Lib\V20\GoogleAdsClientBuilder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use Google\Ads\GoogleAds\V20\Enums\MimeTypeEnum\MimeType;
use Google\Ads\GoogleAds\V20\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V20\Services\SearchGoogleAdsRequest;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;
use Google\Ads\GoogleAds\V20\Services\ListAccessibleCustomersRequest;
use Google\Ads\GoogleAds\V20\Services\ListAccessibleCustomersResponse;

class GoogleAds extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata, HasPerformance, HasSettings, IsIntegration, IsTestable
{
    use Performance;

    private GoogleAdsClient $googleAdsClient;

    private Client $googleClient;

    public static function definition(): IntegrationDefinition
    {
        return IntegrationDefinition::make(
            name: self::getServiceName(),
            displayName: 'Google Ads',
            providerClass: GoogleAdsServiceProvider::class,
            namespaceMap: [],
        );
    }

    public function initialize(): void
    {
        $settings = $this->getSettings();

        if (empty($this->service?->refresh_token)) {
            return;
        }

        $oauth2 = (new OAuth2TokenBuilder)
            ->withClientId($settings['client_id'])
            ->withClientSecret($settings['client_secret'])
            ->withRefreshToken($this->service->refresh_token)
            ->build();

        $this->googleAdsClient = (new GoogleAdsClientBuilder)
            ->withDeveloperToken($settings['developer_token'])
            ->withOAuth2Credential($oauth2)
            ->build();
    }

    public function getSettings($customKeys = null): array
    {
        $settings = parent::getSettings($customKeys);

        return [
            'client_id'       => $settings['GOOGLEADS_CLIENT_ID'] ?? config('googleads.client_id'),
            'client_secret'   => $settings['GOOGLEADS_CLIENT_SECRET'] ?? config('googleads.client_secret'),
            'developer_token' => $settings['GOOGLEADS_DEVELOPER_TOKEN'] ?? config('googleads.developer_token'),
            'redirect_uri'    => config('googleads.redirect_uri'),
        ];
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $configs = $this->getSettings();
        $state = $this->generateRedirectOauthState();

        $oauth2 = new OAuth2([
            'clientId'         => $configs['client_id'],
            'clientSecret'     => $configs['client_secret'],
            'authorizationUri' => config('googleads.auth_uri'),
            'redirectUri'      => $configs['redirect_uri'],
            'scope'            => implode(' ', config('googleads.scope')),
            'state'            => $state,
        ]);

        $authUrl = $oauth2->buildFullAuthorizationUri([
            'access_type'            => config('googleads.access_type'),
            'prompt'                 => config('googleads.prompt'),
            'include_granted_scopes' => 'true',
        ]);

        throw_unless($authUrl, CouldNotInitializePackage::class, 'Google Ads OAuth failed');

        $this->redirectTo((string) $authUrl);
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        if (data_get($tokens, 'access_token')) {
            return new TokenDTO($tokens);
        }

        $code = $tokens['code'] ?? request('code');

        throw_if(empty($code), 'Missing authorization code from Google callback');

        $settings = $this->getSettings();

        $oauth2 = new OAuth2([
            'clientId'           => $settings['client_id'],
            'clientSecret'       => $settings['client_secret'],
            'authorizationUri'   => config('googleads.auth_uri'),
            'redirectUri'        => $settings['redirect_uri'],
            'tokenCredentialUri' => config('googleads.token_uri'),
            'scope'              => config('googleads.scope'),
            'state'              => $this->generateRedirectOauthState(),
            'accessType'         => config('googleads.access_type'),
            'prompt'             => config('googleads.prompt'),
        ]);

        $oauth2->setCode($code);

        $authToken = $oauth2->fetchAuthToken();

        throw_if(
            isset($authToken['error']),
            $authToken['error_description'] ?? 'Unknown error fetching tokens'
        );

        $googleClient = new Client;
        $googleClient->setClientId($settings['client_id']);
        $googleClient->setClientSecret($settings['client_secret']);
        $googleClient->setRedirectUri($settings['redirect_uri']);
        $googleClient->addScope(['openid', 'email', 'profile']);
        $googleClient->setAccessToken([
            'access_token'  => $authToken['access_token'],
            'refresh_token' => $authToken['refresh_token'] ?? null,
            'expires_in'    => $authToken['expires_in'] ?? 3600,
            'created'       => time(),
        ]);

        $this->googleClient = $googleClient;

        $this->service?->update([
            'access_token'  => $authToken['access_token'] ?? null,
            'refresh_token' => $authToken['refresh_token'] ?? null,
            'expires'       => now()->addSeconds($authToken['expires_in'] ?? 3600),
        ]);

        return new TokenDTO($authToken);
    }

    public function getUser(): UserDTO
    {
        if (! isset($this->googleClient)) {
            return new UserDTO([
                'email' => auth()->user()?->email ?? '',
                'photo' => auth()->user()?->profile_photo_url ?? '',
            ]);
        }

        if ($this->googleClient->isAccessTokenExpired()) {
            $this->googleClient->fetchAccessTokenWithRefreshToken(
                $this->googleClient->getRefreshToken()
            );
        }

        $oauth2 = new GoogleServiceOauth2($this->googleClient);
        $profile = $oauth2->userinfo->get();

        return new UserDTO([
            'email' => $profile->getEmail(),
            'photo' => $profile->getPicture(),
        ]);
    }

    public function getAccessibleCustomers(): ?ListAccessibleCustomersResponse
    {
        try {
            $customerService = $this->googleAdsClient->getCustomerServiceClient();

            return $customerService->listAccessibleCustomers(new ListAccessibleCustomersRequest);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return null;
        }
    }

    public function getCustomerFolders(ListAccessibleCustomersResponse $customers, ?GoogleAdObjectType $objectType = null): array
    {
        $customerIds = iterator_to_array($customers->getResourceNames()->getIterator());

        $query = <<<'SQL'
            SELECT customer.id, customer.descriptive_name, customer.manager FROM customer WHERE customer.status = 'ENABLED' LIMIT 1
        SQL;

        $customers = [];
        $managers = [];

        foreach ($customerIds as $resourceName) {
            $id = (string) str($resourceName)->after('customers/');
            $customerResponse = $this->runSingleQuery($query, $id);

            $customer = null;

            foreach ($customerResponse as $row) {
                $customer = $row;

                break;
            }

            if ($customer === null) {
                $this->log('No accessible customer found. Possible customer is NOT_ENABLED.', 'warning', null, compact('id'));

                continue;
            }

            $customerType = $customer->getCustomer()->getManager()
                ? GoogleAdObjectType::MANAGER
                : GoogleAdObjectType::CUSTOMER;

            $entry = [
                'id'          => $id,
                'isDir'       => true,
                'siteDriveId' => $customerType->value,
                'name'        => $customerType->label() . ': ' . $customer->getCustomer()->getDescriptiveName() . " {$id}",
            ];

            if ($customerType === GoogleAdObjectType::MANAGER) {
                $managers[] = $entry;
            }

            if ($customerType === GoogleAdObjectType::CUSTOMER) {
                $customers[] = $entry;
            }
        }

        return match ($objectType) {
            GoogleAdObjectType::MANAGER  => $managers,
            GoogleAdObjectType::CUSTOMER => $customers,
            default                      => [
                ...$managers,
                ...$customers,
            ],
        };
    }

    public function getAccessibleManagers(ListAccessibleCustomersResponse $customers): array
    {
        return $this->getCustomerFolders($customers, GoogleAdObjectType::MANAGER);
    }

    public function getManagerCustomers(string $managerId): array
    {
        $query = <<<'SQL'
            SELECT
              customer_client.client_customer,
              customer_client.descriptive_name,
              customer_client.manager,
              customer_client.level,
              customer_client.status
            FROM customer_client
            WHERE customer_client.level = 1
              AND customer_client.status = 'ENABLED'
        SQL;

        $childCustomers = [];

        foreach ($this->runPaginatedQuery($query, $managerId) as $row) {
            $customer = $row->getCustomerClient();
            $id = (string) str($customer->getResourceName())->after('customerClients/');

            if (filled($id)) {
                $childCustomers[] = [
                    'id'          => $id,
                    'isDir'       => true,
                    'name'        => GoogleAdObjectType::CUSTOMER->label() . ': ' . $customer->getDescriptiveName() . " {$id}",
                    'siteDriveId' => GoogleAdObjectType::CUSTOMER->value,
                    'rawName'     => $customer->getDescriptiveName(),
                ];
            }
        }

        return $childCustomers;
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderObjectId = data_get($request, 'folder_id');
        $folderObjectType = data_get($request, 'site_drive_id');

        if (! $folderObjectId || $folderObjectId == 'root') {
            // Root request get all managers.
            $customersResponse = $this->getAccessibleCustomers();

            if ($customersResponse === null) {
                return [];
            }

            return $this->getAccessibleManagers($customersResponse);
        }

        if ($folderObjectType === GoogleAdObjectType::MANAGER->value) {
            return $this->getManagerCustomers($folderObjectId);
        }

        return [];
    }

    public function paginate(array $request = []): void
    {
        $metadata = data_get($request, 'metadata', []) ?? [];

        foreach ($metadata as $folder) {
            $id = data_get($folder, 'folder_id');
            $objectType = GoogleAdObjectType::tryFrom(data_get($folder, 'site_drive_id'));

            if (empty($id) || empty($objectType)) {
                continue;
            }

            match ($objectType) {
                GoogleAdObjectType::MANAGER  => $this->paginateManager($id),
                GoogleAdObjectType::CUSTOMER => $this->paginateCustomer($id),
                default                      => null,
            };
        }
    }

    public function paginateManager(string $managerId): void
    {
        $customers = $this->getManagerCustomers($managerId);

        foreach ($customers as $customer) {
            $customerId = (string) str(data_get($customer, 'id'))->after('customerClients/');
            $this->paginateCustomer($customerId);
        }
    }

    public function paginateCustomer(string $customerId): void
    {
        $query = <<<'SQL'
            SELECT
              campaign.id,
              campaign.name,
              campaign.status,
              campaign.advertising_channel_type
            FROM campaign
            ORDER BY campaign.name
            PARAMETERS include_drafts=true
        SQL;

        foreach ($this->runPaginatedQuery($query, $customerId) as $campaignRow) {
            $campaignId = (string) $campaignRow->getCampaign()->getId();
            $assetGroups = $this->getAssetGroupsForCampaign($customerId, $campaignId);

            foreach ($assetGroups as $group) {
                $resourceName = sprintf('customers/%s/assetGroups/%s', $customerId, $group['id']);
                $this->getAssetsForAssetGroup($customerId, $resourceName);
            }
        }
    }

    public function getAssetsForAssetGroup(string $customerId, string $assetGroupResourceName): void
    {
        $query = sprintf(
            <<<'SQL'
                SELECT asset_group_asset.asset, asset_group_asset.field_type
                FROM asset_group_asset
                WHERE asset_group_asset.asset_group = '%s'
                PARAMETERS include_drafts=true
            SQL,
            $assetGroupResourceName
        );

        $assets = [];

        foreach ($this->runPaginatedQuery($query, $customerId) as $row) {
            $assetResourceName = $row->getAssetGroupAsset()->getAsset();
            $asset = $this->getAssetByResourceName($customerId, $assetResourceName);

            $id = data_get($asset, 'id');

            if (filled($id) && ! isset($assets[$id])) {
                $assets[$id] = $asset;
            }
        }

        if (filled($assets)) {
            $this->dispatch(array_values($assets), $assetGroupResourceName);
        }
    }

    public function runSingleQuery(string $query, string $customerId): iterable
    {
        try {
            $gaService = $this->googleAdsClient->getGoogleAdsServiceClient();
            $request = resolve(SearchGoogleAdsRequest::class);
            $request->setCustomerId($customerId);
            $request->setQuery($query);
            $response = $gaService->search($request);

            return $response->iterateAllElements();
        } catch (ApiException $e) {
            if (data_get($e->getMetadata(), '0.errors.0.errorCode.authorizationError') === 'CUSTOMER_NOT_ENABLED') {
                // Handle customer not enabled error without adding n+1 queries. Slow api.
                $this->log("Google Ads API query failed: Customer not enabled - {$e->getMessage()}");

                return [];
            }

            if (data_get($e->getMetadata(), '0.errors.0.errorCode.authorizationError') === 'USER_PERMISSION_DENIED') {
                $this->log("Google Ads API query failed: User missing permission - {$e->getMessage()}");

                return [];
            }

            $this->log("Google Ads API query failed: {$e->getMessage()}", 'error');

            return [];
        }
    }

    public function getAssetByResourceName(string $customerId, string $resourceName): ?array
    {
        $gaService = $this->googleAdsClient->getGoogleAdsServiceClient();

        $query = sprintf(
            $this->assetSelectQuery() . <<<'SQL'
                FROM asset
                WHERE asset.resource_name = '%s'
                AND asset.type IN (IMAGE, YOUTUBE_VIDEO)
                PARAMETERS include_drafts=true
            SQL,
            $resourceName
        );

        $request = resolve(SearchGoogleAdsRequest::class);
        $request->setCustomerId($customerId);
        $request->setQuery($query);

        $response = $gaService->search($request);
        $rows = iterator_to_array($response->getIterator(), false);
        $row = count($rows) > 0 ? $rows[0] : null;

        if (! $row) {
            return null;
        }

        return [...$this->extractRowData($row), 'customer_id' => $customerId];
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $youtubeVideoId = data_get($file, 'youtube_video_id');
        $mimeTypeWithUnderscore = data_get($file, 'mime_type');
        $mimeType = $mimeTypeWithUnderscore
            ? str($mimeTypeWithUnderscore)->lower()->replace('_', '/')->toString() // mime types in Google Ads use underscores
            : ($youtubeVideoId ? 'video/mp4' : null);

        $extension = $this->getMimeTypeOrExtension($mimeType);
        $type = $this->getFileTypeFromExtension($extension);
        $fileId = data_get($file, 'id');
        $name = data_get($file, 'name') ?: data_get($file, 'youtube_video_title') ?: $fileId;

        $width = $youtubeVideoId ? null : data_get($file, 'width');
        $height = $youtubeVideoId ? null : data_get($file, 'height');

        return new FileDTO([
            ...$attr,
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'remote_service_file_id' => $youtubeVideoId ?: $fileId,
            'name'                   => $name,
            'slug'                   => str()->slug(pathinfo((string) $name, PATHINFO_FILENAME)),
            'extension'              => $extension ?: ($youtubeVideoId ? 'mp4' : null),
            'type'                   => $type ?: ($youtubeVideoId ? 'video' : null),
            'mime_type'              => $mimeType,
            'size'                   => data_get($file, 'size'),
            'thumbnail'              => data_get($file, 'image_url'),
            'duration'               => data_get($file, 'duration', 0),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'resolution'             => $width && $height ? "{$width}x{$height}" : null,
            // Use page identifier from service metas (fallbacks to common keys)
            'remote_page_identifier' => data_get($attr, 'metadata.folder_id')
                ?? data_get($attr, 'folder_id')
                ?? data_get($attr, 'remote_page_identifier')
                ?? data_get($file, 'remote_page_identifier')
                ?? null,
        ]);
    }

    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        $url = $file->remote_page_identifier;

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        try {
            return response()->streamDownload(function () use ($url) {
                $stream = fopen($url, 'rb');

                while (! feof($stream)) {
                    echo fread($stream, 8192);
                    flush();
                }
                fclose($stream);
            }, $file->name . '.' . $file->extension, [
                'Content-Type'        => $file->mime_type ?? 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . $file->name . '.' . $file->extension . '"',
                'Cache-Control'       => 'no-cache, must-revalidate',
                'Pragma'              => 'public',
            ]);
        } catch (Exception $e) {
            logger()->error("Failed to stream download from service for file ID: {$file->id} - {$e->getMessage()}");

            return false;
        }
    }

    public function getThumbnailPath(mixed $file): ?string
    {
        $thumbnailRemoteURl = data_get($file, 'thumbnail');

        if (empty($thumbnailRemoteURl)) {
            return null;
        }

        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            data_get($file, 'id'),
            str()->slug(data_get($file, 'id')) . '.' . data_get($file, 'extension', 'jpg'),
        );

        $thumbnailContent = Http::timeout(config('queue.timeout'))->get($thumbnailRemoteURl);

        if ($thumbnailContent->successful()) {
            $this->storage->put($thumbnailPath, $thumbnailContent->body());
        }

        return $thumbnailPath;
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        if ($file->type === FunctionsType::Image->value) {
            if (filled($file->thumbnail)) {
                return $file->original_thumbnail;
            }

            throw_if(empty($file->remote_page_identifier), 'Remote file URL is missing');
            throw_unless(filter_var($file->remote_page_identifier, FILTER_VALIDATE_URL), 'Invalid remote file URL');

            $targetPath = Path::join(
                config('manager.directory.originals'),
                $file->id,
                "{$file->slug}.{$file->extension}"
            );

            $response = Http::timeout(config('queue.timeout'))->get($file->remote_page_identifier);

            if ($response->successful()) {
                $this->storage->put($targetPath, $response->body());

                return $targetPath;
            }
        }

        dispatch(new PostToVulcanJob($file, config('services.vulcan.queue')));

        return true;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        if ($file->type === FunctionsType::Image->value) {
            if (filled($file->thumbnail)) {
                return $file->original_thumbnail;
            }

            $url = $file->remote_page_identifier;

            throw_if(empty($url), 'Remote file URL is missing');
            throw_unless(filter_var($url, FILTER_VALIDATE_URL), 'Invalid remote file URL');

            $key = $this->prepareFileName($file);
            $uploadId = $this->createMultipartUpload($key, $file->mime_type ?? 'application/octet-stream');

            throw_unless($uploadId, CouldNotDownloadFile::class, 'Failed to initialize multi-part upload');

            try {
                $chunkSize = config('manager.chunk_size', 5) * 1024 * 1024;
                $partNumber = 1;
                $parts = [];
                $offset = 0;

                while (true) {
                    $headers = [
                        'Range' => "bytes={$offset}-" . ($offset + $chunkSize - 1),
                    ];

                    $response = Http::withHeaders($headers)->get($url);

                    throw_if($response->status() === 404, "File not found at {$url}");

                    if ($response->status() === 416 || $response->body() === '') {
                        break;
                    }

                    $chunk = $response->body();
                    $parts[] = $this->uploadPart($key, $uploadId, $partNumber++, $chunk);

                    $offset += strlen($chunk);

                    if (strlen($chunk) < $chunkSize) {
                        break;
                    }
                }

                return $this->completeMultipartUpload($key, $uploadId, $parts);
            } catch (Throwable $e) {
                $this->log($e->getMessage(), 'error');
                $file->markFailure(
                    FileOperationName::DOWNLOAD,
                    'Google Ads failed to download file',
                    $e->getMessage()
                );

                return false;
            }
        }

        dispatch(new PostToVulcanJob($file, config('services.vulcan.queue')));

        return true;
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Google Ads settings are required');
        abort_if(count(config('googleads.settings')) !== $settings->count(), 406, 'All Settings must be present');

        $clientIdPattern = '/\d{10,15}-[\w-]+\.apps\.googleusercontent\.com$/';
        $clientSecretPattern = '/^[a-zA-Z0-9-_]{24,}$/';
        $developerTokenPattern = '/^[a-zA-Z0-9]{22}$/';

        $clientId = $settings->firstWhere('name', 'GOOGLEADS_CLIENT_ID')?->payload ?? '';
        $clientSecret = $settings->firstWhere('name', 'GOOGLEADS_CLIENT_SECRET')?->payload ?? '';
        $developerToken = $settings->firstWhere('name', 'GOOGLEADS_DEVELOPER_TOKEN')?->payload ?? '';

        abort_unless(preg_match($clientIdPattern, $clientId), 406, 'Invalid client ID format');
        abort_unless(preg_match($clientSecretPattern, $clientSecret), 406, 'Invalid client secret format');
        abort_unless(preg_match($developerTokenPattern, $developerToken), 406, 'Invalid developer token format');

        try {
            $this->googleClient->setClientId($clientId);
            $this->googleClient->setClientSecret($clientSecret);
            $authUrl = $this->googleClient->createAuthUrl();
            $authUrlPattern = '/^https:\/\/accounts\.google\.com\/o\/oauth2\/(auth|v2\/auth)\?/';

            abort_unless(preg_match($authUrlPattern, $authUrl), 406, 'Invalid auth URL format');

            return true;
        } catch (Exception $e) {
            abort(500, $e->getMessage());
        }
    }

    private function getAssetGroupsForCampaign(string $customerId, string $campaignId): array
    {
        $campaignRes = sprintf('customers/%s/campaigns/%s', $customerId, $campaignId);
        $query = sprintf(
            <<<'SQL'
                SELECT asset_group.id, asset_group.name
                FROM asset_group
                WHERE asset_group.campaign = "%s"
                ORDER BY asset_group.name
                PARAMETERS include_drafts=true
            SQL,
            $campaignRes
        );

        $folders = [];

        foreach ($this->runPaginatedQuery($query, $customerId) as $row) {
            $ag = $row->getAssetGroup();

            $folders[] = [
                'id'          => (string) $ag->getId(),
                'isDir'       => true,
                'siteDriveId' => 'Assets',
                'name'        => $ag->getName(),
            ];
        }

        return $folders;
    }

    public function extractRowData(GoogleAdsRow|iterable $row): array
    {
        $asset = $row->getAsset();
        $type = AssetType::name($asset->getType());

        if (! in_array($type, ['IMAGE', 'YOUTUBE_VIDEO'])) {
            return [];
        }

        $imageAsset = $asset->getImageAsset();
        $youtubeAsset = $asset->getYoutubeVideoAsset();

        return [
            'id'                  => $asset->getId(),
            'name'                => $asset->getName(),
            'type'                => $type,
            'mime_type'           => $type === 'IMAGE' ? MimeType::name($imageAsset?->getMimeType()) : null,
            'image_url'           => $imageAsset?->getFullSize()?->getUrl(),
            'size'                => $imageAsset?->getFileSize(),
            'width'               => $imageAsset?->getFullSize()?->getWidthPixels(),
            'height'              => $imageAsset?->getFullSize()?->getHeightPixels(),
            'youtube_video_id'    => $youtubeAsset?->getYoutubeVideoId(),
            'youtube_video_title' => $youtubeAsset?->getYoutubeVideoTitle(),
        ];
    }

    public function runPaginatedQuery(string $query, string $customerId): iterable
    {
        $gaService = $this->googleAdsClient->getGoogleAdsServiceClient();
        $pageToken = null;

        do {
            $request = new SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query'       => $query,
                'page_token'  => $pageToken ?? '',
            ]);

            try {
                $response = $gaService->search($request);
            } catch (Exception $e) {
                if (data_get($e->getMetadata(), '0.errors.0.errorCode.authorizationError') === 'USER_PERMISSION_DENIED') {
                    $this->log("Google Ads API query failed: User missing permission - {$e->getMessage()}");

                    return [];
                }

                $this->log('Google Ads API query failed. Permissions may be missing.', 'error', null, [
                    'message'     => $e->getMessage(),
                    'query'       => $query,
                    'customer_id' => $customerId,
                ]);

                return [];
            }

            yield from $response->iterateAllElements();

            $pageToken = $response->getPage()->getNextPageToken();
        } while ($pageToken);
    }

    private function assetSelectQuery(): string
    {
        return <<<'SQL'
            SELECT
                asset.resource_name,
                asset.id,
                asset.name,
                asset.final_urls,
                asset.type,
                asset.image_asset.full_size.url,
                asset.image_asset.file_size,
                asset.image_asset.mime_type,
                asset.image_asset.full_size.width_pixels,
                asset.image_asset.full_size.height_pixels,
                asset.youtube_video_asset.youtube_video_id,
                asset.youtube_video_asset.youtube_video_title,
                asset.call_to_action_asset.call_to_action
         SQL;
    }
}
