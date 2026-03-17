<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sharepoint;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Support\LazyCollection;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\Enums\PlatformFunctions\FunctionsType;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\MariusCucuruz\DAMImporter\Integrations\Concerns\ManagesOAuthTokens;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;

class Sharepoint extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata, HasSettings, IsTestable
{
    use ManagesOAuthTokens;

    protected Client $client;

    private ?string $accessToken = '';

    private ?string $clientId = null;

    private ?string $clientSecret = null;

    private ?string $tenantId = null;

    private ?string $redirectUri = null;

    protected function getTokenEndpoint(): string
    {
        return str_replace(
            '{tenant_id}',
            $this->tenantId,
            config('sharepoint.token_url')
        );
    }

    protected function getClientCredentials(): array
    {
        return [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
    }

    protected function refreshAccessToken(): void
    {
        try {
            $response = Http::asForm()->post($this->getTokenEndpoint(), [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->service->refresh_token,
                'scope'         => config('sharepoint.scope'),
                ...$this->getClientCredentials(),
            ]);

            if ($response->failed()) {
                $errorCode = $response->json('error') ?? $response->status();
                logger()->error('SharePoint token refresh failed', [
                    'service_id'        => $this->service?->id,
                    'status'            => $response->status(),
                    'error'             => $response->json('error'),
                    'error_description' => $response->json('error_description'),
                ]);

                throw new \MariusCucuruz\DAMImporter\Exceptions\CouldNotRefreshToken("Token refresh failed: {$errorCode}");
            }

            $this->persistRefreshedTokens($response->json());
        } catch (\MariusCucuruz\DAMImporter\Exceptions\CouldNotRefreshToken $e) {
            $this->markServiceUnauthorized();

            throw $e;
        } catch (Exception $e) {
            $this->markServiceUnauthorized();
            logger()->error('SharePoint token refresh exception', [
                'service_id' => $this->service?->id,
                'exception'  => $e->getMessage(),
            ]);

            throw new \MariusCucuruz\DAMImporter\Exceptions\CouldNotRefreshToken('Token refresh failed due to an unexpected error', previous: $e);
        }
    }

    public function initialize(): void
    {
        $this->client = new Client;
        $settings = $this->getSettings();

        $this->clientId = $settings['clientId'];
        $this->clientSecret = $settings['clientSecret'];
        $this->tenantId = $settings['tenantId'];
        $this->redirectUri = $settings['redirectUri'];
        $this->handleTokenExpiration();
    }

    public function getSettings(?array $customKeys = []): array
    {
        $settings = parent::getSettings($customKeys);

        $clientId = $settings['SHAREPOINT_CLIENT_ID'] ?? config('sharepoint.client_id');
        $clientSecret = $settings['SHAREPOINT_SECRET'] ?? config('sharepoint.client_secret');
        $tenantId = $settings['SHAREPOINT_TENANT_ID'] ?? config('sharepoint.tenant_id');

        $redirectUri = config('sharepoint.redirect_uri');

        return compact('clientId', 'clientSecret', 'tenantId', 'redirectUri');
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        try {
            throw_unless(
                $this->clientId && $this->redirectUri,
                CouldNotInitializePackage::class,
                'Sharepoint settings are required!'
            );

            $queryParams = [
                'response_type' => 'code',
                'client_id'     => $this->clientId,
                'redirect_uri'  => $this->redirectUri,
                'scope'         => config('sharepoint.scope'),
                'prompt'        => 'select_account',
                'state'         => json_encode(['settings' => $this->settings?->pluck('id')?->toArray()]),
            ];
            $queryString = http_build_query($queryParams);
            $requestUrl = config('sharepoint.oauth_base_url') . "/organizations/oauth2/v2.0/authorize?{$queryString}";
            $this->redirectTo($requestUrl);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    /**
     * @throws \MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken
     */
    public function getTokens(array $tokens = []): TokenDTO
    {
        try {
            $formParams = [
                'grant_type'    => 'authorization_code',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => config('sharepoint.scope'),
                'code'          => request('code'),
                'redirect_uri'  => $this->redirectUri,
            ];

            $response = $this->client->post(config('sharepoint.oauth_base_url') . "/{$this->tenantId}/oauth2/v2.0/token", [
                'headers'     => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'form_params' => $formParams,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            throw_unless($body, CouldNotGetToken::class, 'Invalid token response');

            return new TokenDTO($this->storeToken($body));
        } catch (Throwable|Exception $e) {
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    public function storeToken($body)
    {
        $this->accessToken = $body['access_token'];
        $expires = now()->addseconds($body['expires_in'])->getTimestamp();

        return [
            'access_token' => $body['access_token'],
            'token_type'   => $body['token_type'],
            'expires'      => $expires,
            // With token body, redirect url too long and fails
            'token'         => null,
            'refresh_token' => $body['refresh_token'],
        ];
    }

    public function getUser(): ?UserDTO
    {
        try {
            $response = $this->client->get(config('sharepoint.query_base_url') . '/me', [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'Accept'        => 'application/json',
                ],
            ]);
            $body = json_decode($response->getBody()->getContents(), true);

            $photo = $this->getAccountPhoto();

            return new UserDTO([
                'email' => data_get($body, 'mail') ?? data_get($body, 'userPrincipalName'),
                'photo' => $photo,
                'name'  => data_get($body, 'displayName') ?? data_get($body, 'userPrincipalName'),
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return new UserDTO;
        }
    }

    public function getAccountPhoto()
    {
        try {
            $response = $this->client->get(config('sharepoint.query_base_url') . '/me/photo/$value', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Accept'        => 'image/jpeg',
                ],
            ]);

            if ($response->getStatusCode() == 200) {
                return $this->uploadThumbnail($response->getBody()->getContents());
            }
        } catch (GuzzleException|Exception $e) {
            // If account has no photo this call will return 404 error. Updated to INFO.
            $this->log("Error getting account photo: {$e->getMessage()}");
        }

        return null;
    }

    public function uploadThumbnail(mixed $file): string
    {
        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            str()->random(6),
            str()->random(6) . '.jpg'
        );
        $file = $this->getFileThumbnail($file);

        $this->storage->put($thumbnailPath, $file);

        return $thumbnailPath;
    }

    /**
     * @throws \Throwable
     */
    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        $this->handleTokenExpiration();

        $tempFilePath = tempnam(sys_get_temp_dir(), config('sharepoint.name') . '_');
        throw_unless($tempFilePath, CouldNotDownloadFile::class, 'Temporary file not found!');

        $fp = fopen($tempFilePath, 'w');

        // Download in 5 MB chunks:
        $chunkSizeBytes = config('manager.chunk_size', 5) * 1048576;
        $chunkStart = 0;

        try {
            $response = $this->client->get(
                config('sharepoint.query_base_url')
                . '/drives/'
                . $file->getMetaExtra('DriveID')
                . '/items/' . $file->remote_service_file_id
                . '?select=id,@microsoft.graph.downloadUrl', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->service->access_token,
                        'Accept'        => 'application/json',
                    ],
                ]
            );
            $this->httpStatus = $response->getStatusCode();

            if ($this->httpStatus === 400 || $this->httpStatus === 401) {
                $this->cleanupTemporaryFile($tempFilePath, $fp);

                return false;
            }

            $body = json_decode((string) $response->getBody(), true);
            $downloadUrl = data_get($body, '@microsoft.graph.downloadUrl');
            throw_unless($body && $downloadUrl, CouldNotDownloadFile::class, 'Download link not found');

            while (true) {
                $chunkEnd = $chunkStart + $chunkSizeBytes;

                $response = $this->client->request('GET', $downloadUrl, [
                    'headers' => [
                        'Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                    ],
                ]);

                if ($response->getStatusCode() !== 206) {
                    break;
                }

                $chunkStart = $chunkEnd + 1;
                $this->downstreamToTmpFile($response->getBody()->getContents());
            }
        } catch (GuzzleException|Throwable|Exception $e) {
            $this->httpStatus = $e->getCode();

            // Handle partial content
            if ($response !== null && $response?->getStatusCode() == 206) {
                $chunkStart = $chunkEnd + 1; // @phpstan-ignore-line
                $this->downstreamToTmpFile($response->getBody()->getContents());
                $this->log('File download completed');
            } else {
                $this->log("Error getting download link: {$e->getMessage()}", 'error', null, $e->getTrace());

                return false;
            }
        }

        return $this->downstreamToTmpFile(null, $this->prepareFileName($file));
    }

    public function getFileThumbnail($file): ?string
    {
        $driveId = data_get($file, 'thumbnail');
        $fileId = data_get($file, 'id');

        if (! $driveId || ! $fileId) {
            return null;
        }

        try {
            $response = $this->client->get(config('sharepoint.query_base_url')
                . '/drives/'
                . $driveId
                . '/items/'
                . $fileId
                . '/thumbnails', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->service->access_token,
                        'Accept'        => 'application/json',
                    ],
                ]);
            $this->httpStatus = $response->getStatusCode();
            $body = json_decode((string) $response->getBody(), true);

            if ($url = data_get($body, 'value.0.large.url')) {
                return $this->uploadThumbnail(file_get_contents($url));
            }
        } catch (GuzzleException|Exception $e) {
            // If file has no photo this call will return 404 error. Updated to INFO.
            $this->log("Error getting thumbnail: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * @throws \Throwable
     */
    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        try {
            $response = $this->client->get(
                config('sharepoint.query_base_url')
                . '/drives/'
                . $file->getMetaExtra('DriveID')
                . '/items/' . $file->remote_service_file_id
                . '?select=id,@microsoft.graph.downloadUrl', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->service->access_token,
                        'Accept'        => 'application/json',
                    ],
                ]
            );
            $this->httpStatus = $response->getStatusCode();

            if ($this->httpStatus === 400 || $this->httpStatus === 401) {
                return false;
            }

            $body = json_decode((string) $response->getBody(), true);
            $downloadUrl = $body['@microsoft.graph.downloadUrl'];

            throw_unless($body && $downloadUrl, CouldNotDownloadFile::class, 'Download link not found');
        } catch (GuzzleException|Throwable|Exception $e) {
            $this->httpStatus = $e->getCode();
            $this->log("Error getting download link: {$e->getMessage()}", 'error', null, $e->getTrace());

            return false;
        }

        return $this->handleMultipartDownload($file, $downloadUrl);
    }

    public function getDirectoryUrlFromFileUrl($fileUrl = null): ?string
    {
        if ($fileUrl) {
            $arr = explode('/', $fileUrl);
            array_pop($arr);

            return implode('/', $arr);
        }

        return null;
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $mimeTypeParts = explode('/', $file['file']['mimeType']);
        $fileExtension = pathinfo(data_get($file, 'name'), PATHINFO_EXTENSION);

        // $dirUrl = $this->getDirectoryUrlFromFileUrl(data_get($file, 'webUrl'));
        // $extra = [
        //     'DriveID'   => data_get($file, 'parentReference.driveId'), // Store Drive ID here, we need this for download later
        //     'view_link' => $dirUrl,
        // ];
        $thumbnail = null;

        if ($mimeTypeParts[0] !== 'audio') {
            $thumbnail = data_get($file, 'parentReference.driveId');
        }

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => data_get($file, 'id'),
            'size'                   => data_get($file, 'size'),
            'name'                   => pathinfo(data_get($file, 'name', ''), PATHINFO_FILENAME),
            'thumbnail'              => $thumbnail,
            'mime_type'              => data_get($file, 'file.mimeType'),
            'type'                   => $this->getFileTypeFromExtension($fileExtension),
            'extension'              => $fileExtension,
            'duration'               => data_get($file, 'video.duration'),
            'slug'                   => str()->slug($file['name']),
            'created_time'           => isset($file['createdDateTime'])
                ? Carbon::parse($file['createdDateTime'])->format('Y-m-d H:i:s')
                : null,
            'modified_time' => isset($file['lastModifiedDateTime'])
                ? Carbon::parse($file['lastModifiedDateTime'])->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public function getMetadataAttributes(?array $properties): array
    {
        $dirUrl = $this->getDirectoryUrlFromFileUrl(data_get($properties, 'webUrl'));

        return [
            'DriveID'   => data_get($properties, 'parentReference.driveId'), // Store Drive ID here, we need this for download later
            'view_link' => $dirUrl,
        ];
    }

    public function getAllSharepointSites(): array
    {
        $siteIds = [];
        $sitesData = [];

        try {
            $response = $this->client->get(config('sharepoint.query_base_url') . '/sites?search=*', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->service->access_token,
                    'Accept'        => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $sites = $body['value'];

            if (! $sites) {
                return [];
            }

            foreach ($sites as $site) {
                $sitesData[] = $site;
                $siteIds[] = $site['id'];
            }

            return [$siteIds, $sitesData];
        } catch (GuzzleException|Exception $e) {
            $this->log('Error getting Sharepoint Sites: ' . $e->getMessage(), 'error');
        }

        return [null, null];
    }

    public function getSharepointSiteDrives($siteId): array
    {
        $siteDriveIds = [];
        $siteDrivesData = [];

        try {
            $response = $this->client->get(config('sharepoint.query_base_url') . '/sites/' . $siteId . '/drives', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->service->access_token,
                ],
            ]);
            $this->httpStatus = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);
            $siteDrives = $body['value'];

            foreach ($siteDrives as $siteDrive) {
                $siteDriveIds[] = $siteDrive['id'];
                $siteDrivesData[] = $siteDrive;
            }

            return [$siteDriveIds, $siteDrivesData];
        } catch (GuzzleException|Exception $e) {
            $this->log('Error getting Sharepoint Drive for Site ID: ' . $siteId . '. ' . $e->getMessage(), 'error');
        }

        return [null, null];
    }

    public function getFilesInFolder($folderId = 'root', $siteId = null, $siteDriveId = null): iterable
    {
        $nestedFolders = [];
        $nestedFiles = [];

        try {
            $response = $this->client->get(config('sharepoint.query_base_url') . '/sites/' . $siteId . '/drives/' . $siteDriveId . '/items/' . $folderId . '/children', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->service->access_token,
                ],
            ]);
            $this->httpStatus = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);
            $driveItems = $body['value'];

            foreach ($driveItems as $driveItem) {
                if (isset($driveItem['folder']) && $driveItem['folder']['childCount'] > 0) {
                    $nestedFolders[] = $driveItem;
                } elseif (isset($driveItem['file'])) {
                    $nestedFiles[] = $driveItem;
                }
            }
        } catch (GuzzleException|Exception $e) {
            $this->httpStatus = $e->getCode();
            $this->log('Error getting Sharepoint Drive for Site ID: ' . $siteId . '. ' . $e->getMessage(), 'error');
        }

        return [$nestedFiles, $nestedFolders];
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = $request['folder_id'] ?? 'root';
        $siteId = $request['site_id'] ?? null;
        $siteDriveId = $request['site_drive_id'] ?? null;

        if (! $folderId || $folderId === 'root') {
            // If no or root input, get all available sites
            $folders = [];
            $sites = $this->getAllSharepointSites()[1];

            if (! $sites) {
                return [];
            }

            foreach ($sites as $site) {
                $folders[] = [
                    'id'    => $site['id'],
                    'isDir' => true,
                    ...$site,
                ];
            }

            return $folders;
        } elseif ($siteId && ! $siteDriveId) {
            // If only site is set, get site drive items as folderId == siteDriveId
            $items = $this->getDriveItems($siteId, $folderId);
            $folders = [];

            foreach ($items as $item) {
                $folders[] = [
                    'id'          => $item['id'],
                    'isDir'       => isset($item['folder']),
                    'siteId'      => $siteId,
                    'siteDriveId' => $folderId,
                    ...$item,
                ];
            }

            return $folders;
        } elseif ($siteId && $siteDriveId) {
            // If site and site drive is set, get drive items as folderId == driveFolderId
            $files = $this->getFilesInFolder($folderId, $siteId, $siteDriveId)[0];
            $folders = $this->getFilesInFolder($folderId, $siteId, $siteDriveId)[1];

            $filesItems = [];
            $folderItems = [];

            if ($files) {
                foreach ($files as $file) {
                    $filesItems[] = [
                        'id'          => $file['id'],
                        'isDir'       => false,
                        'siteId'      => $siteId,
                        'siteDriveId' => $siteDriveId,
                        ...$file,
                    ];
                }
            }

            if ($folders) {
                foreach ($folders as $folder) {
                    $folderItems[] = [
                        'id'          => $folder['id'],
                        'isDir'       => true,
                        'siteId'      => $siteId,
                        'siteDriveId' => $siteDriveId,
                        ...$folder,
                    ];
                }
            }

            return [...$filesItems, ...$folderItems];
        } else {
            // If only folderId is set to not null or root, get site drives as folderId == siteId
            $siteDrives = $this->getSharepointSiteDrives($folderId)[1];
            $folders = [];

            // Typically will be one
            foreach ($siteDrives as $siteDrive) {
                $folders[] = [
                    'id'     => $siteDrive['id'],
                    'siteId' => $folderId,
                    'isDir'  => true,
                    ...$siteDrive,
                ];
            }

            return $folders;
        }
    }

    public function listFolderSubFolders(?array $request): iterable
    {
        $driveFiles = [];

        foreach ($request['metadata'] as $item) {
            $item = collect($item);

            if (isset($item['site_id']) && isset($item['site_drive_id'])) {
                // Handle a site drive folder input
                $driveFiles = $this->processDriveFolder($item['folder_id'], $driveFiles, $item['site_id'], $item['site_drive_id']);
            } elseif (isset($item['site_id']) && ! isset($item['site_drive_id'])) {
                // Handle a site drive input
                $driveItems = $this->getDriveItems($item['site_id'], $item['folder_id']);

                if ($driveItems) {
                    foreach ($driveItems as $driveItem) {
                        $driveFiles = $this->processDriveItem($driveItem, $driveFiles, $item['site_id'], $item['folder_id']);
                    }
                }
            } else {
                // Handle a site input
                $siteDriveIds = $this->getSharepointSiteDrives($item['folder_id'])[0];

                if (! $siteDriveIds) {
                    continue;
                }

                foreach ($siteDriveIds as $siteDriveId) {
                    $driveItems = $this->getDriveItems($item['folder_id'], $siteDriveId);

                    if ($driveItems) {
                        foreach ($driveItems as $driveItem) {
                            $driveFiles = $this->processDriveItem($driveItem, $driveFiles, $item['folder_id'], $siteDriveId);
                        }
                    }
                }
            }
        }

        return $driveFiles;
    }

    /**
     * @throws \Throwable
     */
    public function downloadFromService(File $file): StreamedResponse|BinaryFileResponse|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        try {
            $chunkSizeBytes = config('manager.chunk_size', 5) * 1024 * 1024;
            $chunkStart = 0;
            $downloadUrl = null;

            try {
                $response = $this->client->get(
                    config('sharepoint.query_base_url')
                    . '/drives/'
                    . $file->getMetaExtra('DriveID')
                    . '/items/' . $file->remote_service_file_id
                    . '?select=id,@microsoft.graph.downloadUrl', [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $this->service->access_token,
                            'Accept'        => 'application/json',
                        ],
                    ]
                );
                $this->httpStatus = $response->getStatusCode();
                $body = json_decode((string) $response->getBody(), true);
                $downloadUrl = $body['@microsoft.graph.downloadUrl'];

                throw_unless($body && $downloadUrl, CouldNotDownloadFile::class, 'Download link not found');
            } catch (GuzzleException|Throwable|Exception $e) {
                $this->log('Error getting download link: ' . $e->getMessage(), 'error');
            }

            // Create a streamed response
            $response = response()->streamDownload(function () use (&$chunkStart, $chunkSizeBytes, $downloadUrl) {
                while (true) {
                    $chunkEnd = $chunkStart + $chunkSizeBytes;

                    try {
                        $response = $this->client->request('GET', $downloadUrl, [
                            'headers' => [
                                'Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                            ],
                        ]);

                        if ($response->getStatusCode() == 206) {
                            echo $response->getBody()->getContents();
                            $chunkStart = $chunkEnd + 1;
                        } else {
                            break;
                        }
                    } catch (Exception $e) {
                        if ($e->getResponse()->getStatusCode() == 416) {
                            $this->log('File download from service completed');
                        }

                        break;
                    }
                }
            }, $file->name);

            // Set headers for file download
            $response->headers->set('Content-Type', $file->mime_type);
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $file->name . '.' . $file->extension . '"');
            $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
            $response->headers->set('Pragma', 'public');

            return $response;
        } catch (Exception $e) {
            if ($e->getResponse()->getStatusCode() == 416) {
                $this->log('File download from service completed');
            } else {
                $this->log($e->getMessage(), 'error');
            }
        }

        return false;
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'SharePoint settings are required');
        abort_if(count(config('sharepoint.settings')) !== $settings->count(), 406, 'All Settings must be present');

        // Pattern for SharePoint client_id and tenant_id (UUID format)
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        $clientSecretPattern = '/^[a-zA-Z0-9~\-]{30,}$/';

        $clientId = $settings->firstWhere('name', 'SHAREPOINT_CLIENT_ID')?->payload ?? '';
        $clientSecret = $settings->firstWhere('name', 'SHAREPOINT_SECRET')?->payload ?? '';
        $tenantId = $settings->firstWhere('name', 'SHAREPOINT_TENANT_ID')?->payload ?? '';

        abort_unless(preg_match($uuidPattern, $clientId), 406, 'Looks like your client ID format is invalid');
        abort_unless(preg_match($clientSecretPattern, $clientSecret), 406, 'Looks like your client secret format is invalid');
        abort_unless(preg_match($uuidPattern, $tenantId), 406, 'Looks like your tenant ID format is invalid');

        return true;
    }

    public function paginate(?array $request = []): void
    {
        $this->initialize();

        if (isset($request['metadata'])) {
            foreach ($request['metadata'] as $metadata) {
                $siteId = data_get($metadata, 'site_id');
                $siteDriveId = data_get($metadata, 'site_drive_id');
                $folderId = data_get($metadata, 'folder_id', 'root');

                $files = iterator_to_array($this->getFilesInFolder($folderId, $siteId, $siteDriveId));
                $this->dispatch($files, "{$siteId}-{$siteDriveId}-{$folderId}");
            }

            return;
        }

        try {
            $driveFiles = [];

            $siteIds = $this->getAllSharepointSites()[0];

            if (! $siteIds) {
                $this->dispatch([], 'root');

                return;
            }

            foreach ($siteIds as $siteId) {
                $siteDriveIds = $this->getSharepointSiteDrives($siteId)[0];

                if (! $siteDriveIds) {
                    continue;
                }

                foreach ($siteDriveIds as $siteDriveId) {
                    $driveItems = $this->getDriveItems($siteId, $siteDriveId);

                    if ($driveItems) {
                        foreach ($driveItems as $driveItem) {
                            $driveFiles = $this->processDriveItem($driveItem, $driveFiles, $siteId, $siteDriveId);
                        }
                    }
                }
            }

            $this->dispatch($driveFiles, 'root');
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    private function getDriveItems($siteId, $siteDriveId)
    {
        return LazyCollection::make(function () use ($siteId, $siteDriveId) {
            $query = config('sharepoint.query_base_url') . "/sites/{$siteId}/drives/{$siteDriveId}/items/root/children?top=" . config('view.pagination');

            while (true) {
                try {
                    $response = $this->client->get($query, [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $this->service->access_token,
                            'Accept'        => 'application/json',
                        ],
                    ]);
                    $this->httpStatus = $response->getStatusCode();
                    $body = json_decode($response->getBody()->getContents(), true);

                    throw_unless($body, CouldNotGetToken::class, 'Invalid token response');

                    $driveItems = $body['value'] ?? [];

                    yield from $driveItems;

                    if (! isset($body['@odata.nextLink'])) {
                        break;
                    } else {
                        $query = $body['@odata.nextLink'];
                    }
                } catch (Exception $e) {
                    $this->httpStatus = $e->getCode();
                    $this->log('Error getting Sharepoint Drive for Site ID: ' . $siteId . '. ' . $e->getMessage(), 'error');

                    break;
                }
            }
        });
    }

    private function processDriveItem($driveItem, $driveFiles, $siteId, $siteDriveId): array
    {
        // If DriveItem is Folder need to recursively get children
        if (isset($driveItem['folder']) && $driveItem['folder']['childCount'] > 0) {
            $driveFiles = $this->processDriveFolder($driveItem['id'], $driveFiles, $siteId, $siteDriveId);
        } elseif (isset($driveItem['file'])) {
            $mimeTypeParts = explode('/', $driveItem['file']['mimeType']);
            $fileType = $mimeTypeParts[0];
            $fileExtension = pathinfo(data_get($driveItem, 'name'), PATHINFO_EXTENSION);

            if (! in_array($fileExtension, config('manager.meta.file_extensions'))) {
                return $driveFiles;
            }

            if ($fileType === FunctionsType::Audio->value
                || $fileType === FunctionsType::Video->value
                || $fileType === FunctionsType::Image->value) {
                $driveFiles[] = $driveItem;
            }
        }

        return $driveFiles;
    }

    private function processDriveFolder($driveFolderId, $driveFiles, $siteId, $siteDriveId): array
    {
        // Recursively get children
        $nestedFolders = [];

        while (true) {
            $folderFiles = $this->getFilesInFolder($driveFolderId, $siteId, $siteDriveId)[0];
            $nestedFolders = [...$nestedFolders, ...$this->getFilesInFolder($driveFolderId, $siteId, $siteDriveId)[1]];

            if ($folderFiles) {
                foreach ($folderFiles as $file) {
                    $mimeTypeParts = explode('/', $file['file']['mimeType']);
                    $fileType = $mimeTypeParts[0];
                    $fileExtension = pathinfo(data_get($file, 'name'), PATHINFO_EXTENSION);

                    if (! in_array($fileExtension, config('manager.meta.file_extensions'))) {
                        continue;
                    }

                    if ($fileType === FunctionsType::Audio->value
                        || $fileType === FunctionsType::Video->value
                        || $fileType === FunctionsType::Image->value) {
                        $driveFiles[] = $file;
                    }
                }
            }

            if (empty($nestedFolders)) {
                break;
            }

            $driveFolderId = array_shift($nestedFolders)['id'];
        }

        return $driveFiles;
    }
}
