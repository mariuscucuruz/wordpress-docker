<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Dropbox;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use MariusCucuruz\DAMImporter\Support\DataObject;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Http\Response;
use Kunnu\Dropbox\DropboxApp;
use Illuminate\Support\Carbon;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use Illuminate\Support\Facades\Cache;
use Kunnu\Dropbox\Models\AccessToken;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Kunnu\Dropbox\Models\FileMetadata;
use Kunnu\Dropbox\Dropbox as DropboxSdk;
use Kunnu\Dropbox\Models\FolderMetadata;
use Illuminate\Database\Eloquent\Collection;
use Kunnu\Dropbox\Models\File as DropboxFile;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Integrations\Dropbox\Traits\DownloadsFiles;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotQuery;
use MariusCucuruz\DAMImporter\Pagination\PaginationType;
use Kunnu\Dropbox\Exceptions\DropboxClientException;
use MariusCucuruz\DAMImporter\Integrations\Dropbox\Traits\ListsFilesAndFolders;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use MariusCucuruz\DAMImporter\Pagination\PaginatedResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;

class Dropbox extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata, HasSettings, IsTestable
{
    use DownloadsFiles;
    use ListsFilesAndFolders;

    private ?string $accessToken;

    protected function getPaginationType(): PaginationType
    {
        return PaginationType::Cursor;
    }

    protected function getRootFolders(): array
    {
        return [['id' => '']];
    }

    protected function fetchPage(?string $folderId, mixed $cursor, array $folderMeta = []): PaginatedResponse
    {
        $path = $folderId ?? '';

        if ($cursor !== null) {
            $url = '/files/list_folder/continue';
            $params = ['cursor' => $cursor];
        } else {
            $url = '/files/list_folder';
            $params = ['path' => $path, 'limit' => config('dropbox.per_page')];
        }

        $accessToken = $this->getAccessToken();
        $dropbox = $this->initialize($accessToken);

        try {
            $response = $dropbox->postToAPI($url, $params, $accessToken);
            $this->httpStatus = $response->getHttpStatusCode();
            $body = $response->getDecodedBody();

            if (in_array($this->httpStatus, [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED])) {
                return new PaginatedResponse([], [], null);
            }

            $responseToModel = $dropbox->makeModelFromResponse($response);

            $items = [];
            $subfolders = [];

            /** @var DropboxFile|FolderMetadata|FileMetadata $file */
            foreach ($responseToModel->getItems() as $file) {
                if ($file->getDataProperty('.tag') === 'folder') {
                    $subfolders[] = ['id' => $file->getPathLower()];

                    continue;
                }

                $items[] = $file;
            }

            $nextCursor = ($body['has_more'] && $body['cursor']) ? $body['cursor'] : null;

            return new PaginatedResponse($items, $subfolders, $nextCursor);
        } catch (Exception $e) {
            $this->httpStatus = $e->getCode();
            $this->log($e->getMessage(), 'error');

            return new PaginatedResponse([], [], null);
        }
    }

    protected function transformItems(array $items): array
    {
        $transformed = [];

        /** @var DropboxFile|FileMetadata|FolderMetadata $file */
        foreach ($items as $file) {
            $extension = pathinfo($file->getDataProperty('name'), PATHINFO_EXTENSION);

            if (! $this->isExtensionSupported($extension)) {
                continue;
            }

            $metadata = match (true) {
                $file instanceof DropboxFile    => $file->getMetadata()->getData(),
                $file instanceof FileMetadata   => $file->getData(),
                $file instanceof FolderMetadata => $file->getData(),
                default                         => [],
            };

            $transformed[] = [
                'isDir'        => false,
                'thumbnailUrl' => '',
                'name'         => $file->getName(),
                'path'         => $file->getPathLower(),
                'file_id'      => $file->getId(),
                'metadata'     => [
                    ...DataObject::toArray($metadata),
                    'fileTag'   => $file->getTag() ?? null,
                    'fileData'  => $file->getData() ?? null,
                    'mediaInfo' => DataObject::toArray($file->getMediaInfo()),
                ],
                ...$file->getData(),
            ];
        }

        return $transformed;
    }

    protected function getImportGroupName(?string $folderId, array $folderMeta, int $pageCount): string
    {
        return empty($folderId) ? 'root' : $folderId;
    }

    protected function filterSupportedExtensions(array $items): array
    {
        // Extension filtering is done in transformItems for Dropbox
        // because we need access to the File object to get the extension
        return $items;
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        try {
            $settings = $this->getSettings();
            $authHelper = $this->initialize()->getAuthHelper();
            $token = $authHelper->getAccessToken(request('code'), null, $settings['redirectUri']);
            $this->accessToken = $token->access_token;

            if (! $token || empty($this->accessToken)) {
                throw new CouldNotGetToken('Failed to acquire an access token');
            }

            return $this->buildTokenDTO($token);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        try {
            $this->settings = $settings;

            $dropbox = $this->initialize();
            $authHelper = $dropbox->getAuthHelper();

            $params = [
                'force_reauthentication' => 'true',
            ];

            if (isset($this->settings) && $this->settings->count()) {
                $params['state'] = json_encode(['settings' => $this->settings->pluck('id')->toArray()]);
            }

            $authUrl = $authHelper->getAuthUrl(
                $this->getSettings()['redirectUri'],
                $params,
                null,
                config('dropbox.access_type')
            );

            if (! $authUrl) {
                throw new CouldNotInitializePackage('Failed to generate the auth URL');
            }

            $this->redirectTo($authUrl);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            throw new Exception(class_basename($this) . ': ' . __FUNCTION__ . ': ' . $e->getMessage());
        }
    }

    public function search(?array $params = [], array $extensions = [])
    {
        $accessToken = $this->getAccessToken();

        try {
            $searchTerm = '';
            $extensions[] = 'ico';

            foreach ($extensions as $extension) {
                $searchTerm = ($searchTerm ? " {$searchTerm} OR " : '') . "*.{$extension}";
            }

            $params ??= [
                'query'   => $searchTerm,
                'options' => ['max_results' => 100, 'filename_only' => false, 'file_extensions' => $extensions],
                'path'    => '',
            ];

            $dropbox = $this->initialize($accessToken);
            $response = $dropbox->postToAPI('/files/search_v2', $params, $accessToken);
            throw_unless($response, CouldNotQuery::class, 'Search query returned no results');

            $this->httpStatus = $response->getHttpStatusCode();

            if (
                $this->httpStatus >= Response::HTTP_INTERNAL_SERVER_ERROR
                || in_array($this->httpStatus, [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED])
            ) {
                return false;
            }

            $response = $dropbox->makeModelFromResponse($response);
            $matches = [];

            foreach (data_get($response, 'matches', []) as $match) {
                $matches[] = $match['metadata'];
            }

            return $matches;
        } catch (Exception|Throwable $e) {
            $this->httpStatus = $e->getCode();
            $this->log($e->getMessage(), 'error');

            throw new CouldNotQuery($e->getMessage());
        }
    }

    public function getUser(): ?UserDTO
    {
        try {
            $dropbox = $this->initialize($this->accessToken ?? $this->service?->access_token ?? null);
            $account = $dropbox->getCurrentAccount();

            return new UserDTO([
                'email'     => $account->getEmail(),
                'photo'     => $account->getProfilePhotoUrl(),
                'accountId' => $account->getAccountId(),
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return new UserDTO;
        }
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $metadataName = data_get($file, 'metadata.name');
        $name = filled($metadataName) ? pathinfo($metadataName, PATHINFO_FILENAME) : null;
        $extension = $this->getFileExtensionFromFileName($metadataName);
        $type = $this->getFileTypeFromExtension($extension);
        $mimeType = $this->getMimeTypeOrExtension($extension) ?: Path::join($type, $extension);

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => $this->uniqueFileId($file, 'metadata.id'),
            'name'                   => $name,
            'thumbnail'              => data_get($file, 'metadata.id'),
            'mime_type'              => $mimeType,
            'type'                   => $type,
            'extension'              => $extension,
            'size'                   => data_get($file, 'metadata.size'),
            'slug'                   => str($name)->slug()->toString(),
            'modified_time'          => data_get($file, 'metadata.client_modified')
                ? Carbon::parse(data_get($file, 'metadata.client_modified'))->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public function getMetadataAttributes(?array $properties): array
    {
        return data_get($properties, 'metadata') ?? $properties;
    }

    public function uploadThumbnail($file): ?string
    {
        $thumbnailUrl = data_get($file, 'thumbnail') ?? data_get($file, 'remote_service_file_id');
        throw_if(empty($thumbnailUrl), 'Missing thumbnail URL for Dropbox file', compact('file'));

        $key = 'dropbox-thumbnail-cache:' . sha1($thumbnailUrl);

        return Cache::remember($key, now()->addMinutes(15), function () use ($file, $key, $thumbnailUrl) {
            $dropbox = $this->initialize($this->service->access_token);

            try {
                $thumbnail = $dropbox->getThumbnail($thumbnailUrl, 'large');
            } catch (DropboxClientException $e) {
                logger()?->error("unable to download dropbox thumbnail:{$thumbnailUrl}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                Cache::forget($key);

                return null;
            }

            return $this->storeDataAsFile(
                $thumbnail->getContents(),
                $this->prepareFileName(($file instanceof File) ? $file : null),
                'thumbnails'
            );
        });
    }

    public function initialize(?string $accessToken = null): DropboxSdk
    {
        $settings = $this->getSettings();
        $dropboxApp = new DropboxApp($settings['clientId'], $settings['clientSecret'], $accessToken);

        return new DropboxSdk($dropboxApp);
    }

    public function getSettings($customKeys = null): array
    {
        $settings = parent::getSettings($customKeys);

        $clientId = $settings['DROPBOX_CLIENT_ID'] ?? config('dropbox.client_id');
        $clientSecret = $settings['DROPBOX_SECRET_ID'] ?? config('dropbox.client_secret');
        $redirectUri = config('dropbox.redirect_uri');

        return compact('clientId', 'clientSecret', 'redirectUri');
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Dropbox settings are required');
        abort_if(count(config('dropbox.settings')) !== $settings->count(), 406, 'All Settings must be present');

        // Define the pattern for Dropbox client ID and client secret
        $clientIdPattern = '/^[a-z0-9]{15}$/'; // Example pattern for Dropbox client ID
        $clientSecretPattern = '/^[a-z0-9]{15}$/'; // Example pattern for Dropbox client secret

        $clientId = $settings->firstWhere('name', 'DROPBOX_CLIENT_ID')?->payload ?? '';
        $clientSecret = $settings->firstWhere('name', 'DROPBOX_SECRET_ID')?->payload ?? '';

        abort_if(! preg_match($clientIdPattern, $clientId), 406, 'Looks like your Dropbox client ID format is invalid');
        abort_if(! preg_match($clientSecretPattern, $clientSecret),
            406,
            'Looks like your Dropbox client secret format is invalid');

        try {
            // Initiate the Dropbox SDK with the provided credentials
            $dropboxApp = new DropboxApp($clientId, $clientSecret);
            $dropbox = new DropboxSdk($dropboxApp);

            // Get the auth URL to verify if the credentials are valid
            // Note that we're not redirecting the user, just getting the URL
            $authHelper = $dropbox->getAuthHelper();
            $authUrl = $authHelper->getAuthUrl($this->getSettings()['redirectUri']);

            abort_if(! $authUrl, 406, 'Failed to generate auth URL with provided credentials');

            return true;
        } catch (Exception $e) {
            abort(500, $e->getMessage());
        }
    }

    public function getAccessToken(): string
    {
        $dropbox = $this->initialize($this->service->access_token);

        if (empty($this->service->access_token) || $this->checksForRefreshTokenExpiry($this->service->expires)) {
            try {
                $token = $dropbox->getAuthHelper()
                    ->getRefreshedAccessToken(new AccessToken($this->service->options ?? []));

                $this->service->update([
                    'access_token' => $token->access_token,
                    'expires'      => now()->addSeconds($token->getExpiryTime())->timestamp,
                ]);
            } catch (Exception $e) {
                if ($e->getCode() === 401) {
                    $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
                }
                $this->log('Failed to refresh access token: ' . $e->getMessage(), 'error');
            }
        }

        return $this->service->access_token;
    }

    protected function checksForRefreshTokenExpiry($expires): bool
    {
        $expiresDate = Carbon::createFromTimestamp($expires);

        return $expiresDate->subMinutes(2)->isPast();
    }

    protected function buildTokenDTO($token): TokenDTO
    {
        return new TokenDTO([
            'access_token'  => $token->access_token,
            'token_type'    => $token->token_type,
            'expires'       => now()->addseconds($token->getExpiryTime())->getTimestamp(),
            'scope'         => $token->scope,
            'uid'           => $token->uid,
            'token'         => $token,
            'refresh_token' => $token->refresh_token ?? null,
        ]);
    }
}
