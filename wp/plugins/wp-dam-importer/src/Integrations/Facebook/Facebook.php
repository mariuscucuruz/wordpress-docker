<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Facebook;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use GuzzleHttp\Client;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Support\LazyCollection;
use Illuminate\Http\File as FileSystem;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Interfaces\HasRateLimit;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotQuery;
use MariusCucuruz\DAMImporter\Traits\ServiceRateLimiter;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Interfaces\HasDateRangeFilter;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;

class Facebook extends SourceIntegration implements CanPaginate, HasDateRangeFilter, HasFolders, HasMetadata, HasRateLimit, HasSettings, IsTestable
{
    use ServiceRateLimiter;

    public Client $client;

    private ?string $accessToken = '';

    private ?string $clientId;

    private ?string $clientSecret;

    private ?string $configId;

    private ?string $redirectUri;

    public function initialize(): void
    {
        $this->client = new Client;
        $settings = $this->getSettings();
        $this->clientId = $settings['clientId'];
        $this->clientSecret = $settings['clientSecret'];
        $this->redirectUri = $settings['redirectUri'];
        $this->configId = $settings['configId'];

        $this->validateSettings();
        $this->handleTokenExpiration();
    }

    public function getSettings($customKeys = null): array
    {
        $settings = parent::getSettings($customKeys);

        $clientId = $settings['FACEBOOK_CLIENT_ID'] ?? config('facebook.client_id');
        $clientSecret = $settings['FACEBOOK_SECRET'] ?? config('facebook.client_secret');
        $configId = $settings['FACEBOOK_CONFIG_ID'] ?? config('facebook.config_id');

        $redirectUri = config('facebook.redirect_uri');

        return compact('clientId', 'clientSecret', 'redirectUri', 'configId');
    }

    public function validateSettings(): bool
    {
        throw_if(empty($this->clientId), InvalidSettingValue::make('Client ID'), 'Client ID is missing!');
        throw_if(empty($this->clientSecret), InvalidSettingValue::make('Client Secret'), 'Client Secret is missing!');
        throw_if(empty($this->configId), InvalidSettingValue::make('Config ID'), 'Config ID is missing!');
        throw_if(empty($this->redirectUri), InvalidSettingValue::make('Redirect URI'), 'Redirect URI is missing!');

        return true;
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Facebook settings are required');
        abort_if(count(config('facebook.settings')) !== $settings->count(), 406, 'All Settings must be present');

        $clientIdPattern = '/^[a-zA-Z0-9]{15,}$/'; // max: 32?
        $clientSecretPattern = '/^[a-zA-Z0-9]{32,}$/'; // max: 64?
        $configIdPattern = '/^[a-zA-Z0-9]{15,}$/'; // max: 32?

        $clientId = $settings->firstWhere('name', 'FACEBOOK_CLIENT_ID')?->payload ?? '';
        $clientSecret = $settings->firstWhere('name', 'FACEBOOK_SECRET')?->payload ?? '';
        $configId = $settings->firstWhere('name', 'FACEBOOK_CONFIG_ID')?->payload ?? '';

        abort_if(! preg_match($clientIdPattern, $clientId), 406, 'Looks like your client ID format is invalid');
        abort_if(! preg_match($clientSecretPattern, $clientSecret), 406, 'Looks like your client secret format is invalid');
        abort_if(! preg_match($configIdPattern, $configId), 406, 'Looks like your config ID format is invalid');

        return true;
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        try {
            $url = config('facebook.oauth_base_url') . '/dialog/oauth';

            $state = $this->generateRedirectOauthState();

            $queryParams = [
                'client_id'    => $this->clientId,
                'redirect_uri' => $this->redirectUri,
                'config_id'    => $this->configId,
                'auth_type'    => 'reauthenticate',
                'state'        => $state,
            ];

            throw_unless(
                $url && $this->clientId && $this->configId && $this->redirectUri,
                CouldNotInitializePackage::class,
                'Facebook settings are required!'
            );

            $queryString = http_build_query($queryParams);
            $requestUrl = "{$url}?{$queryString}";
            $this->redirectTo($requestUrl);
        } catch (CouldNotInitializePackage|CouldNotQuery|Throwable|Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    /**
     * @throws CouldNotGetToken
     */
    public function getTokens(array $tokens = []): TokenDTO
    {
        try {
            $response = $this->client->get(config('facebook.query_base_url') . '/oauth/access_token', [
                'query' => [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'redirect_uri'  => $this->redirectUri,
                    'code'          => request('code'),
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            throw_unless($body, CouldNotGetToken::class, 'Invalid token response.');

            return new TokenDTO($this->storeToken($body));
        } catch (CouldNotGetToken|Throwable|Exception $e) {
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    public function storeToken($body): array
    {
        throw_unless(isset($body['access_token'], $body['token_type']), CouldNotGetToken::class, 'Invalid token response.');

        $this->accessToken = $body['access_token'];
        isset($body['expires_in']) ? $expires = now()->addseconds($body['expires_in'])->getTimestamp() : $expires = null;

        $longLiveToken = $this->getNewLongLiveToken($this->accessToken);

        throw_unless($longLiveToken, CouldNotGetToken::class, 'Failed to get long live token.');

        if ($longLiveToken) {
            $this->accessToken = $longLiveToken[0];
            $expires = $longLiveToken[1];
        }

        return [
            'access_token'  => $this->accessToken,
            'token_type'    => $body['token_type'],
            'expires'       => $expires,
            'token'         => null,
            'refresh_token' => null, // Graph API does not use refresh tokens
        ];
    }

    public function getNewLongLiveToken($token): ?array
    {
        try {
            $response = $this->client->get(config('facebook.query_base_url') . '/oauth/access_token', [
                'query' => [
                    'client_id'         => $this->clientId,
                    'client_secret'     => $this->clientSecret,
                    'grant_type'        => 'fb_exchange_token',
                    'fb_exchange_token' => $token,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            throw_unless(isset($body['access_token']), CouldNotGetToken::class, 'Access token not found in the response');

            isset($body['expires_in']) ? $expires = now()->addseconds($body['expires_in'])->getTimestamp() : $expires = null;

            return [$body['access_token'], $expires];
        } catch (CouldNotGetToken|Throwable|Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return null;
    }

    public function getUser(): ?UserDTO
    {
        try {
            $response = $this->client->get(config('facebook.query_base_url') . '/me', [
                'query' => [
                    'access_token' => $this->accessToken,
                    'Accept'       => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            $userId = $body['id'];
            $response = $this->client->get(config('facebook.query_base_url') . '/' . $userId, [
                'query' => [
                    'fields'       => 'id,name,picture.width(720).height(720).as(picture)',
                    'access_token' => $this->accessToken,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            throw_if(! isset($body['name']), CouldNotQuery::class, 'Neither name nor email found in the response');

            return new UserDTO([
                'email'   => data_get($body, 'name'),
                'photo'   => $this->uploadThumbnail(null, data_get($body, 'picture.data.url')),
                'name'    => data_get($body, 'name'),
                'user_id' => $userId,
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return new UserDTO;
    }

    public function getPageAssets(?array $params = null): iterable
    {
        $pageData = [];

        if ($params) {
            $pageData['data'] = [
                ['id' => $params['id'], 'access_token' => $params['access_token']],
            ];
        } else {
            $pageData = $this->getPages();
        }

        throw_unless(isset($pageData['data']), CouldNotQuery::class, 'No data in pageData');

        // Collect batch requests
        $batchRequests = [];

        foreach ($pageData['data'] as $page) {
            $postsFields = 'id,created_time,updated_time,full_picture,permalink_url,attachments,properties';
            $videoFields = 'id,created_time,updated_time,permalink_url,properties,from{access_token},source,thumbnails,length';

            // FIXME: Known issues to fix when FB API updated.
            // 1. Page posts endpoint - bug returning video source download url
            // 2. Page photos endpoint - bug returning blank array for uploaded photos
            // Page videos endpoint work as expected.
            // Current solution: Get both page posts and videos and filter out videos from posts.
            $batchRequests[] = [
                'method'       => 'GET',
                'relative_url' => $page['id'] . '/videos?limit=' . config('facebook.video_per_page') . '&fields=' . $videoFields .
                    '&type=uploaded&include_headers=false&access_token=' . $page['access_token'],
            ];

            $batchRequests[] = [
                'method'       => 'GET',
                'relative_url' => $page['id'] . '/posts?' . config('facebook.per_page') . '=100&fields=' . $postsFields .
                    '&type=photo&include_headers=false&access_token=' . $page['access_token'],
            ];
        }

        $batchRequestBody = json_encode($batchRequests);

        return LazyCollection::make(function () use ($batchRequestBody) {
            $accessToken = $this->service->access_token;
            $assets = [];

            try {
                $batchUrl = config('facebook.query_base_url') . '/?batch=' . urlencode($batchRequestBody) . '&access_token=' . $accessToken;
                $response = $this->client->post($batchUrl);
                $this->httpStatus = $response->getStatusCode();
                $batchResponse = json_decode($response->getBody()->getContents(), true);
                throw_unless(is_array($batchResponse), CouldNotQuery::class, 'Batch response is not an array');

                foreach ($batchResponse as $itemResponse) {
                    throw_unless(isset($itemResponse['body']), CouldNotQuery::class, 'Body not found in itemResponse');

                    $body = json_decode($itemResponse['body'], true);

                    if (isset($body['data'])) {
                        $assets = [...$assets, ...$body['data']];
                    }

                    if (isset($body['paging']['next'])) {
                        yield from $this->getBatchPaginatedAssets($body['paging']['next']);
                    }
                }
            } catch (Exception $e) {
                $this->httpStatus = $e->getCode();
                $this->log($e->getMessage(), 'error');
            }
            yield from $assets;
        });
    }

    public function getPages(?string $nextUrl = null): array
    {
        $query = $nextUrl ?? (config('facebook.query_base_url') . '/me/accounts');
        $options = $nextUrl ? [] : [
            'query' => [
                'access_token' => $this->service->access_token,
                'fields'       => 'id,name,picture,access_token',
                'limit'        => config('facebook.per_page'),
            ],
        ];

        try {
            $response = $this->client->get($query, $options);

            return json_decode($response->getBody()->getContents(), true);
        } catch (Exception $e) {
            $this->checkAndHandleServiceAuthorisation();
            $this->log($e->getMessage(), 'error');

            return [];
        }
    }

    public function getPage($pageId)
    {
        $query = config('facebook.query_base_url') . '/' . $pageId;

        try {
            $response = $this->client->get($query, [
                'query' => [
                    'access_token' => $this->service->access_token,
                    'fields'       => 'id,name,picture,access_token',
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (Exception|GuzzleException $e) {
            $this->checkAndHandleServiceAuthorisation();
            $this->log($e->getMessage(), 'error');

            return [];
        }
    }

    /**
     * @deprecated
     */
    public function getBatchPaginatedAssets($query): iterable
    {
        return LazyCollection::make(function () use ($query) {
            while (true) {
                try {
                    $response = $this->client->get($query);

                    $body = json_decode($response->getBody()->getContents(), true);

                    yield from $body['data'];

                    if (! isset($body['paging']['next'])) {
                        break;
                    } else {
                        $query = $body['paging']['next'];
                    }
                } catch (Exception $e) {
                    $this->log($e->getMessage(), 'error');

                    break;
                }
            }
        });
    }

    public function uploadThumbnail(mixed $file = null, $source = null): string
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

        $this->storage->put($thumbnailPath, file_get_contents($source));

        return $thumbnailPath;
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $thumbnailPath = data_get($file, 'thumbnail') // Child thumbnail
            ?: data_get($file, 'attachments.data.0.media.image.src') // Photo or Video asset
                ?: data_get($file, 'thumbnails.data.0.uri');

        if (isset($file['source'])) { // 'source' video specific
            return $this->videoProperties($file, $attr, $thumbnailPath);
        }

        return $this->photoProperties($file, $attr, $thumbnailPath);
    }

    public function photoProperties($file, $attr, $thumbnailPath): FileDTO
    {
        $name = isset($file['created_time']) ? Carbon::parse($file['created_time'])->format('Y-m-d H:i:s') : null;

        if (isset($file['child_count'])) {
            $name .= '(' . $file['child_count'] . ')';
        }

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => data_get($file, 'id'),
            'created_time'           => isset($file['created_time']) ? Carbon::parse($file['created_time'])->format('Y-m-d H:i:s') : null,
            'modified_time'          => isset($file['updated_time']) ? Carbon::parse($file['updated_time'])->format('Y-m-d H:i:s') : null,
            'name'                   => $name,
            'thumbnail'              => $thumbnailPath,
            'mime_type'              => 'image/jpeg',
            'type'                   => 'image',
            'extension'              => 'jpg',
            'slug'                   => ! empty($file['created_time'])
                ? str()->slug(Carbon::parse($file['created_time'])->format('Y-m-d H:i:s'))
                : str()->slug(now()->format('Y-m-d H:i:s')),
            'remote_page_identifier' => data_get($file, 'page_id'),
        ]);
    }

    public function videoProperties($file, $attr, $thumbnailPath): FileDTO
    {
        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => $this->uniqueFileId($file),
            'created_time'           => isset($file['created_time']) ? Carbon::parse($file['created_time'])->format('Y-m-d H:i:s') : null,
            'modified_time'          => isset($file['updated_time']) ? Carbon::parse($file['updated_time'])->format('Y-m-d H:i:s') : null,
            'name'                   => isset($file['created_time'])
                ? Carbon::parse($file['created_time'])->format('Y-m-d H:i:s')
                : now()->format('Y-m-d H:i:s'),
            'thumbnail' => $thumbnailPath,
            'mime_type' => 'video/mp4',
            'type'      => 'video',
            'extension' => 'mp4',
            'duration'  => isset($file['length']) ? $file['length'] * 1000 : null,
            'slug'      => isset($file['created_time'])
                ? str()->slug(Carbon::parse($file['created_time'])->format('Y-m-d H:i:s'))
                : str()->slug(now()->format('Y-m-d H:i:s')),
            'remote_page_identifier' => data_get($file, 'page_id'),
        ]);
    }

    public function getMetadataAttributes(?array $properties): array
    {
        $metadata = [];

        if (isset($properties['full_picture'])) {
            $metadata['source_link'] = $properties['full_picture'];
        }

        if (isset($properties['permalink_url'])) {
            $metadata['view_link'] = $properties['permalink_url'];
        }

        if (isset($properties['source'])) {
            $metadata['source_link'] = $properties['source'];
        }

        if (isset($properties['permalink_url'])) {
            $viewUrl = data_get($properties, 'permalink_url');
            $urlHost = parse_url(data_get($properties, 'permalink_url'), PHP_URL_HOST);

            if (empty($urlHost)) {
                $viewUrl = 'https://www.facebook.com' . $properties['permalink_url'];
            }

            $metadata['view_link'] = $viewUrl;
        }

        return $metadata + $properties;
    }

    /**
     * @throws Throwable
     */
    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $this->handleTokenExpiration();

        $tempFilePath = tempnam(sys_get_temp_dir(), config('facebook.name') . '_');
        throw_unless($tempFilePath, CouldNotDownloadFile::class, 'Temporary file not found!');

        $fp = fopen($tempFilePath, 'wb');

        if (! $downloadUrl = $file->getMetaExtra('source_link')) {
            $this->cleanupTemporaryFile($tempFilePath, $fp);

            return false;
        }

        $headResponse = $this->client->head($downloadUrl);
        $fileSize = $headResponse->getHeader('Content-Length')[0] ?? null;

        if (! $fileSize || ! is_numeric($fileSize)) {
            $this->cleanupTemporaryFile($tempFilePath, $fp);

            return false;
        }

        $fileSize = (int) $fileSize;

        $chunkSizeBytes = config('manager.chunk_size', 5) * 1048576;
        $chunkStart = 0;

        while ($chunkStart < $fileSize) {
            $chunkEnd = $chunkStart + $chunkSizeBytes - 1;

            // Adjust the chunk end for the last part of the file
            if ($chunkEnd > $fileSize - 1) {
                $chunkEnd = $fileSize - 1;
            }

            try {
                $response = $this->client->request('GET', $downloadUrl, [
                    'headers' => [
                        'Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                    ],
                ]);

                // Handle both partial (206) and complete (200) responses
                if (in_array($response->getStatusCode(), [Response::HTTP_OK, Response::HTTP_PARTIAL_CONTENT])) {
                    fwrite($fp, $response->getBody()->getContents());
                    $chunkStart = $chunkEnd + 1;
                } else {
                    break;
                }
            } catch (Exception|GuzzleException $e) {
                $this->log("Error getting download link: {$e->getMessage()}", 'error');

                $this->cleanupTemporaryFile($tempFilePath, $fp);

                return false;
            }
        }

        $fullKey = $this->prepareFileName($file);

        $path = $this->storage->putFileAs(
            dirname($fullKey),
            new FileSystem($tempFilePath),
            basename($fullKey)
        );

        $fileSize = $this->getFileSize($path);

        $file->update(['size' => $fileSize]);

        $this->cleanupTemporaryFile($tempFilePath, $fp);

        return $path;
    }

    /**
     * @throws Throwable
     */
    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $downloadUrl = $file->getMetaExtra('source_link');

        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'Download URL is not set.');

        return $this->handleMultipartDownload($file, $downloadUrl);
    }

    /**
     * @throws Throwable
     */
    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        // Issue with source_link expiry. To renew need context of page id & page access_token.
        // TODO: Update index method to save reference to page id. Use download_url for now.
        $downloadUrl = $file->download_url ?? $file->getMetaExtra('source_link');

        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'Download URL is not set.');

        return $this->handleDownloadFromService($file, $downloadUrl);
    }

    public function listFolderContent(?array $request): iterable
    {
        // List files in current dir
        $folderId = data_get($request, 'folder_id') ?? 'root';

        if (! $folderId || $folderId === 'root') {
            // If no or root input, get available pages
            $folders = [];
            $nextUrl = null;

            do {
                $pages = $this->getPages($nextUrl);

                if (empty($pages)) {
                    return $folders;
                }

                foreach (data_get($pages, 'data') ?? [] as $page) {
                    unset($page['access_token']);
                    $folders[] = [
                        'id'    => $page['id'],
                        'isDir' => true,
                        'name'  => sprintf('%s (%s)', $page['name'] ?? '', $page['id'] ?? ''),
                    ];
                }
                $nextUrl = data_get($pages, 'paging.next');
            } while (filled($nextUrl) && count($folders) < config('manager.folder_modal_pagination_limit'));

            return $folders;
        }

        return []; // Only display page level
    }

    /**
     * @deprecated
     */
    public function listFolderSubFolders(?array $request): iterable
    {
        // Gets the files and folders in the current folder.
        $files = [];

        if (isset($request['folder_ids'])) {
            foreach ($request['folder_ids'] as $folderId) {
                $files = $this->getFilesInFolder($folderId);
            }
        }

        return $files;
    }

    public function getFilesInFolder(?string $folderId = 'root'): iterable
    {
        // Gets the files in the current folder and all its nested sub-folders recursively.
        if ($folderId && $folderId !== 'root') { // Handle individual page
            $pageInfo = $this->getPage($folderId);
            $pageAccessToken = $pageInfo['access_token'];
            $pageItems = $this->getPageAssets(['id' => $folderId, 'access_token' => $pageAccessToken]);
        } else {
            $pageItems = $this->getPageAssets();
        }

        $assets = [];

        foreach ($pageItems as $item) {
            $isPhoto = isset($item['link']);
            $extension = $isPhoto ? 'jpeg' : 'mp4';
            $name = $isPhoto ? ($item['name'] ?? Carbon::parse($item['created_time'])) : ($item['description'] ?? Carbon::parse($item['created_time']));

            $assets[] = [
                'id'    => $item['id'],
                'isDir' => false,
                'name'  => $name . '.' . $extension,
                ...$item,
            ];
        }

        return $assets;
    }

    public function checksForRefreshTokenExpiry($expires): bool
    {
        // long lived token also checks if its 0
        if (! $expires) {
            return true;
        }

        $currentTime = time();
        $now = date('Y-m-d H:i:s', $currentTime);

        return $expires <= $now;
    }

    private function handleTokenExpiration(): void
    {
        if ($this->service?->expires && $this->checksForRefreshTokenExpiry($this->service->expires)) {
            try {
                $response = $this->client->get(config('facebook.query_base_url') . '/oauth/access_token', [
                    'query' => [
                        'client_id'         => $this->clientId,
                        'client_secret'     => $this->clientSecret,
                        'grant_type'        => 'fb_exchange_token',
                        'fb_exchange_token' => $this->service?->access_token,
                    ],
                ]);

                $body = json_decode($response->getBody()->getContents(), true);

                if (isset($body['access_token'])) {
                    $this->service->access_token = $body['access_token'];
                    $this->accessToken = $body['access_token'];

                    $debugTokenResponse = $this->client->get(config('facebook.query_base_url') . '/debug_token', [
                        'query' => [
                            'input_token'  => $body['access_token'],
                            'access_token' => $this->clientId . '|' . $this->clientSecret, // App Access Token
                        ],
                    ]);

                    $tokenData = json_decode($debugTokenResponse->getBody()->getContents(), true);

                    $expiresAt = $tokenData['data']['expires_at'];
                    $this->service->expires = $expiresAt;
                    $this->service->save();
                }
            } catch (Exception $e) {
                $this->checkAndHandleServiceAuthorisation();
                $this->log('Failed to refresh access token: ' . $e->getMessage(), 'error');
            }
        }
    }

    public function isServiceAuthorised(): bool
    {
        $this->incrementAttempts();

        $response = Http::timeout(config('queue.timeout'))->get(config('facebook.query_base_url') . '/me/accounts', [
            'access_token' => $this->service->access_token,
            'fields'       => 'id',
            'limit'        => 1,
        ]);

        if ($response->failed() || empty(data_get($response->json(), 'data'))) {
            return false;
        }

        return true;
    }

    public function checkAndHandleServiceAuthorisation(): void
    {
        if ($this->isServiceAuthorised() === false) {
            $this->log("Service unauthorization triggered: name={$this->service?->name}, id={$this->service?->id}", 'warning');
            $this->service?->update(['status' => IntegrationStatus::UNAUTHORIZED]);
        }
    }

    public function paginate(array $request = []): void
    {
        $this->setRateLimitRemainder();
        $this->handleTokenExpiration();

        $folders = data_get($request, 'metadata') ?? [];

        if (empty($folders)) {
            $this->log('No folders found for Facebook sync', 'error');

            return;
        }

        foreach ($folders as $folder) {
            $id = data_get($folder, 'folder_id');
            $startDateInput = data_get($folder, 'start_time');
            $endDateInput = data_get($folder, 'end_time');

            if (!empty($startDateInput) && !empty($endDateInput)) {
                $this->log('Invalid date range for folder ID: ' . $id, 'error');

                continue;
            }

            $pageInfo = $this->getPage($id);
            $pageAccessToken = data_get($pageInfo, 'access_token');

            if (empty($pageAccessToken)) {
                $this->log('No access token found for Page ID: ' . $id, 'error');

                continue;
            }

            $batchUrl = $this->getBatchUrl(['id' => $id, 'access_token' => $pageAccessToken]);
            $this->getAllFiles($batchUrl, $id);
        }
    }

    public function getAssetsWithChildren(array $assets, string $pageId): array
    {
        $assetsWithChildren = [];

        foreach ($assets as $item) {
            data_set($item, 'page_id', $pageId);
            $attachments = data_get($item, 'attachments'); // Post media
            $type = data_get($attachments, 'data.0.type'); // Post endpoint specific
            $source = data_get($item, 'source'); // Video endpoint specific

            // Skip videos from post endpoint here.
            if ($type && ! in_array($type, config('facebook.posts_endpoint_accepted_formats'))) {
                continue;
            }

            // Skip if post with no download url
            if ($type && ! data_get($item, 'full_picture')) {
                continue;
            }

            // Skip if not a photo or video
            if (empty($attachments) && empty($source)) {
                continue;
            }

            $assetsWithChildren[] = $item; // Add parent

            if ($postAttachments = data_get($item, 'attachments')) {
                foreach (data_get($postAttachments, 'data', []) as $attachmentData) {
                    foreach (data_get($attachmentData, 'subattachments.data', []) as $i => $subAttachmentData) {
                        // Original item is duplicated in children, ignore first child
                        if ($i == 0) {
                            continue;
                        }

                        $source = data_get($subAttachmentData, 'media.image.src');
                        $target = data_get($subAttachmentData, 'target');

                        if ($source && $target) {
                            $file = $item;
                            $file['id'] = data_get($target, 'id');
                            $file['child_count'] = $i;
                            $file['full_picture'] = $source;
                            $file['thumbnail'] = $source;
                            $file['page_id'] = $pageId;
                            $assetsWithChildren[] = $file; // Add child
                        }
                    }
                }
            }
        }

        return $assetsWithChildren;
    }

    public function getAllFiles(string $endpoint, $pageId): void
    {
        $this->incrementAttempts();

        try {
            $response = $this->client->post($endpoint);

            $this->httpStatus = $response->getStatusCode();

            $batchResponse = json_decode($response->getBody()->getContents(), true);

            throw_unless(is_array($batchResponse), CouldNotQuery::class, 'Batch response is not an array');

            foreach ($batchResponse as $itemResponse) {
                $responseBody = data_get($itemResponse, 'body');

                if (! $responseBody || ! json_validate($responseBody)) {
                    $this->log('Body not found in itemResponse or is not JSON', 'warn');

                    continue;
                }

                $body = json_decode($responseBody, true);

                if ($assets = data_get($body, 'data')) {
                    // Update assets to include child/attachment items
                    $assets = $this->getAssetsWithChildren($assets, $pageId);

                    if ($this->isDateSyncFilter) {
                        $assets = collect($assets)
                            ->reject(fn ($asset) => ! $this->isWithinDatePeriod(data_get($asset, 'created_time')))
                            ->values()->toArray();
                    }
                    $this->dispatch($assets, $pageId);
                }

                if (data_get($body, 'error')) {
                    $this->checkAndHandleServiceAuthorisation();
                    $this->log(
                        "Error with media request for Page ID: {$pageId}. " .
                        'Error: ' . data_get($body, 'error.message') . '. ' .
                        'Type: ' . data_get($body, 'error.type') . '. ' .
                        'Code: ' . data_get($body, 'error.code') . '. ' .
                        'FB trace ID: ' . data_get($body, 'error.fbtrace_id') . '.',
                        'error'
                    );
                }

                if (isset($body['paging']['next'])) {
                    $this->getNextPageAsset($body['paging']['next'], $pageId);
                }
            }
        } catch (Exception $e) {
            $this->httpStatus = $e->getCode();
            $this->checkAndHandleServiceAuthorisation();
            $this->log($e->getMessage(), 'error');
        }
    }

    public function getNextPageAsset($query, $pageId): void
    {
        $this->incrementAttempts();

        try {
            $response = $this->client->get($query);

            $body = json_decode($response->getBody()->getContents(), true);
            $assets = $this->getAssetsWithChildren(data_get($body, 'data') ?? [], $pageId);

            if ($this->isDateSyncFilter) {
                $assets = collect($assets)
                    ->reject(fn ($asset) => ! $this->isWithinDatePeriod(data_get($asset, 'created_time')))
                    ->values()->toArray();
            }

            $this->dispatch($assets, $pageId);

            if (isset($body['paging']['next'])) {
                $this->getNextPageAsset($body['paging']['next'], $pageId);
            }
        } catch (Exception $e) {
            $this->checkAndHandleServiceAuthorisation();
            logger()->error($e->getMessage());
        }
    }

    public function setRateLimitRemainder(): void
    {
        $max = config('facebook.rate_limit');
        $amount = 0;

        try {
            $response = $this->client->get(config('facebook.query_base_url') . '/me', [
                'query' => [
                    'access_token' => $this->accessToken !== '' ? $this->accessToken : $this->service->access_token,
                    'Accept'       => 'application/json',
                ],
            ]);
            $headers = $response->getHeaders();
            $count = json_decode($headers['x-app-usage'][0])->call_count;

            if ($count > 0) {
                $remainingCalls = max(0, $max - ($max * $count / 100));
                $amount = max(0, $max - $remainingCalls);
            }
        } catch (Exception $e) {
            $this->checkAndHandleServiceAuthorisation();
            logger($e->getMessage());
            $amount = $max;
        }
        logger($amount);
        RateLimiter::remaining($this->cacheKey(), $amount);
    }

    public function getBatchUrl(mixed $page): string
    {
        // @note: Facebook API has bugs in the following endpoints:
        // 1. Page posts endpoint - bug returning video source download url
        // 2. Page photos endpoint - bug returning blank array for uploaded photos
        // 3. Page videos endpoint work as expected.
        // Current solution: Get both page posts and videos separately. Then filter out videos from posts.
        $batchRequests[] = [
            'method'       => 'GET',
            'relative_url' => Path::join(data_get($page, 'id'), 'videos') . '?' . http_build_query(
                [
                    'limit'           => config('facebook.video_per_page'),
                    'fields'          => config('facebook.fields.videos'),
                    'type'            => 'uploaded',
                    'include_headers' => false,
                    'access_token'    => data_get($page, 'access_token'),
                    'since'           => $this->syncFilterDateRange?->start->timestamp,
                    'until'           => $this->syncFilterDateRange?->end->timestamp,
                ]
            ),
        ];

        $batchRequests[] = [
            'method'       => 'GET',
            'relative_url' => Path::join(data_get($page, 'id'), 'posts') . '?' . http_build_query(
                [
                    'limit'           => config('facebook.per_page'),
                    'fields'          => config('facebook.fields.posts'),
                    'type'            => 'photo',
                    'include_headers' => false,
                    'access_token'    => data_get($page, 'access_token'),
                    'since'           => $this->syncFilterDateRange?->start->timestamp,
                    'until'           => $this->syncFilterDateRange?->end->timestamp,
                ]
            ),
        ];

        return config('facebook.query_base_url') . '?' . http_build_query(
            [
                'batch'        => json_encode($batchRequests, JSON_THROW_ON_ERROR),
                'access_token' => $this->service->access_token,
            ]
        );
    }
}
