<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Peach;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use RuntimeException;
use GuzzleHttp\Client;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Support\Carbon;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Support\LazyCollection;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\SourceIntegration;
use Symfony\Component\HttpFoundation\Response;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\MariusCucuruz\DAMImporter\Integrations\Concerns\ManagesOAuthTokens;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotQuery;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;

// Each Peach Oauth account only allows 1 oauth app and 3 redirect urls
// Oauth only authorised on localhost, demo and QA currently.
// Docs: https://doc.api.peach.me/docs/peach-downstream-api
class Peach extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata, HasSettings, IsTestable
{
    use ManagesOAuthTokens;

    protected Client $client;

    private ?string $clientId = null;

    private ?string $clientSecret = null;

    private ?string $redirectUri = null;

    private ?string $accessToken = '';

    public function initialize(): void
    {
        $this->client = new Client;

        $settings = $this->getSettings();

        $this->clientId = $settings['clientId'];
        $this->clientSecret = $settings['clientSecret'];
        $this->redirectUri = $settings['redirectUri'];
    }

    public function paginate(?array $request = []): void
    {
        $this->initialize();

        $this->handleTokenExpiration();

        if (isset($request['folder_ids'])) {
            foreach ($request['folder_ids'] as $folderId) {
                $files = $this->getFilesInFolder((string) $folderId);
                $this->dispatch(is_array($files) ? $files : iterator_to_array($files), (string) $folderId);
            }

            return;
        }

        try {
            $adList = $this->getAdsInLibrary();

            throw_unless($adList, CouldNotQuery::class, 'Failed to fetch ad list');

            $adAssets = [];

            if ($adList) {
                foreach ($adList as $ad) {
                    $adAssets = [...$adAssets, ...$this->getAdAssets($ad)];
                }
            }

            $assetItems = [];

            if ($adAssets) {
                foreach ($adAssets as $asset) {
                    if ($assetInfo = $this->getAssetInfo($asset)) {
                        $assetItems = [...$assetItems, ...$assetInfo];
                    }
                }
            }

            $this->dispatch($assetItems, 'root');
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function getSettings(?array $customKeys = []): array
    {
        $settings = parent::getSettings($customKeys);

        $clientId = $settings['PEACH_CLIENT_ID'] ?? config('peach.client_id');
        $clientSecret = $settings['PEACH_CLIENT_SECRET'] ?? config('peach.client_secret');

        $redirectUri = config('peach.redirect_uri');

        return compact('clientId', 'clientSecret', 'redirectUri');
    }

    protected function getClientCredentials(): array
    {
        return [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        try {
            $url = config('peach.oauth_base_url') . '/authorize';

            throw_unless(
                $url && $this->clientId && $this->clientSecret && $this->redirectUri,
                CouldNotInitializePackage::class,
                'Peach settings are required!'
            );

            $queryParams = [
                'response_type' => 'code',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri'  => $this->redirectUri,
                'scope'         => config('peach.scope'),
                'state'         => json_encode(['settings' => $this->settings?->pluck('id')?->toArray()]),
            ];

            $queryString = http_build_query($queryParams);
            $requestUrl = "{$url}?{$queryString}";

            $this->redirectTo($requestUrl);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        try {
            $response = $this->client->post(config('peach.oauth_base_url') . '/token', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'grant_type'    => 'authorization_code',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code'          => request('code'),
                    'redirect_uri'  => $this->redirectUri,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            return new TokenDTO($this->storeToken($body));
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    public function getUser(): ?UserDTO
    {
        try {
            $response = $this->client->request('GET', config('peach.query_base_url') . '/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Accept'        => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            throw_unless($body, CouldNotGetToken::class, 'User not found in response');

            return new UserDTO([
                'email' => $body['email'],
                'name'  => $body['account'],
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return new UserDTO;
        }
    }

    public function refreshAccessToken()
    {
        try {
            $response = $this->client->post(config('peach.oauth_base_url') . '/token', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    'grant_type'    => 'refresh_token',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $this->service->refresh_token,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            throw_unless($body, CouldNotGetToken::class, 'Invalid token response.');

            if (data_get($body, 'access_token')) {
                $this->service->access_token = $body['access_token'];
                $this->service->save();
            }

            if (data_get($body, 'refresh_token')) {
                $this->service->refresh_token = $body['refresh_token'];
                $this->service->save();
            }

            if (data_get($body, 'expires_in')) {
                $expires = now()->addseconds($body['expires_in'])->getTimestamp();
                $this->service->expires = $expires;
                $this->service->save();
            }

            $this->service->save();
        } catch (Throwable|Exception $e) {
            if ($e->getCode() === 401) {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }
            $this->log($e->getMessage(), 'error');
        }
    }

    public function getAdsInLibrary(): iterable
    {
        return LazyCollection::make(function () {
            $adList = [];
            $startAfterRow = 0;

            while (true) {
                try {
                    $response = $this->client->request('GET', config('peach.query_base_url') . '/ads/library?limit=' . config('view.pagination') . '&after_row=' . $startAfterRow, [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $this->service->access_token,
                            'Accept'        => 'application/json',
                        ],
                    ]);

                    $body = json_decode($response->getBody()->getContents(), true);
                    $totalRows = $body['meta']['totalRows'];

                    throw_unless($body['data']['ads'], CouldNotQuery::class, 'No ads found in library');

                    foreach ($body['data']['ads'] as $ad) {
                        if ($ad['status'] == 'Accepted') { // Update as needed
                            $adList[] = $ad;
                        }
                    }

                    yield from $adList;

                    $startAfterRow += config('view.pagination');

                    if ($totalRows <= $startAfterRow) {
                        break;
                    }
                } catch (CouldNotQuery|Exception $e) {
                    $this->log($e->getMessage(), 'error');
                }
            }
        });
    }

    public function getAdAssets($ad): array
    {
        $adAssets = [];

        try {
            $response = $this->client->request('GET', config('peach.query_base_url') . '/ads/' . $ad['id'] . '/assets', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->service->access_token,
                    'Accept'        => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            throw_unless(data_get($body, 'data.assets'), CouldNotQuery::class, 'No assets found for ad');

            if ($body) {
                $assets = data_get($body, 'data.assets');

                foreach ($assets as $asset) {
                    if (data_get($asset, 'status') == 'Accepted') {
                        $asset['duration'] = $ad['duration'];
                        $asset['campaign_id'] = data_get($ad, 'campaignId');
                        $asset['ad_id'] = data_get($ad, 'id');
                        $adAssets[] = $asset;
                    }
                }
            }
        } catch (CouldNotQuery|GuzzleException|Throwable|Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return $adAssets;
    }

    public function getAssetInfo($asset): bool|array
    {
        $assetItems = [];

        try {
            $response = $this->client->request('GET', config('peach.query_base_url') . '/assets/' . $asset['id'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->service->access_token,
                    'Accept'        => 'application/json',
                ],
            ]);
            $body = json_decode($response->getBody()->getContents(), true);

            throw_unless($body, CouldNotQuery::class, 'No assets information found');

            if ($body) {
                $body['duration'] = $asset['duration'];
                $body['campaign_id'] = data_get($asset, 'campaign_id');
                $body['ad_id'] = data_get($asset, 'ad_id');

                $assetItems[] = $body;
            }
        } catch (CouldNotQuery|GuzzleException|Throwable|Exception $e) {
            $this->log($e->getMessage());

            return false;
        }

        return $assetItems;
    }

    /**
     * @throws \Throwable
     */
    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        // Download in 5 MB chunks:
        $chunkSizeBytes = config('manager.chunk_size', 5) * 1048576;
        $chunkStart = 0;

        try {
            $response = $this->client->get(config('peach.query_base_url') . '/assets/' . $file->remote_service_file_id, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->service->access_token,
                    'Content-Type'  => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            throw_unless(data_get($body, 'url'), CouldNotDownloadFile::class, 'Download URL not found');

            while (true) {
                $chunkEnd = $chunkStart + $chunkSizeBytes;
                $response = $this->client->request('GET', data_get($body, 'url'), [
                    'headers' => [
                        'Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                    ],
                ]);

                if ($response->getStatusCode() !== Response::HTTP_PARTIAL_CONTENT) {
                    break;
                }

                $chunkStart = $chunkEnd + 1;

                $this->downstreamToTmpFile($response->getBody()->getContents());
            }
        } catch (GuzzleException|Throwable|Exception $e) {
            // Handle partial content
            // BUG: $response, chunkEnd are undefined and $chunkStart is not used:
            if ($response->getStatusCode() === Response::HTTP_PARTIAL_CONTENT) {
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

    /**
     * @throws \Throwable
     */
    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        $this->handleTokenExpiration();

        try {
            $response = $this->client->get(config('peach.query_base_url') . '/assets/' . $file->remote_service_file_id, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->service->access_token,
                    'Content-Type'  => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            throw_unless(isset($body['url']), CouldNotDownloadFile::class, 'Download URL not found');

            return $this->handleMultipartDownload($file, $body['url']);
        } catch (Exception $e) {
            $this->log("Error getting download link: {$e->getMessage()}", 'error');

            return false;
        }
    }

    /**
     * @throws \Throwable
     */
    public function downloadFromService(File $file): StreamedResponse|bool
    {
        throw_unless(isset($file['remote_service_file_id']), RuntimeException::class, 'File id is not set');

        $this->handleTokenExpiration();

        $downloadUrl = null;

        try {
            $response = $this->client->get(config('peach.query_base_url') . '/assets/' . $file->remote_service_file_id, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->service->access_token,
                    'Content-Type'  => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            throw_unless(isset($body['url']), CouldNotDownloadFile::class, 'Download URL not found.');

            $downloadUrl = $body['url'];
        } catch (Exception $e) {
            $this->log('Error getting download link: ' . $e->getMessage(), 'error');
        }

        // Create a streamed response
        $response = response()->streamDownload(function () use ($downloadUrl) {
            $chunkSizeBytes = config('manager.chunk_size', 5) * 1024 * 1024;
            $chunkStart = 0;

            while (true) {
                $chunkEnd = $chunkStart + $chunkSizeBytes;

                try {
                    $response = $this->client->request('GET', $downloadUrl, [
                        'headers' => [
                            'Range' => sprintf('bytes=%s-%s', $chunkStart, $chunkEnd),
                        ],
                    ]);

                    if ($response->getStatusCode() !== Response::HTTP_PARTIAL_CONTENT) {
                        break;
                    }

                    echo $response->getBody()->getContents();
                    $chunkStart = $chunkEnd + 1;
                } catch (GuzzleException|Throwable|Exception $e) {
                    if ($e->getResponse()->getStatusCode() === Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE) {
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
    }

    public function uploadThumbnail(mixed $file): string
    {
        return '';
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $extension = $this->getFileExtensionFromFileName(data_get($file, 'name'));
        $mimeType = Path::join(strtolower($file['mediaType']), $extension) ?: $this->getMimeTypeOrExtension($extension);
        $type = strtolower(data_get($file, 'mediaType')) ?: $this->getFileTypeFromExtension($extension);

        if (isset($file['duration'])) {
            $time = explode(':', $file['duration']);
            $minutes = (int) $time[0];
            $seconds = (int) $time[1];
            $milliseconds = (int) $time[2];

            $duration = (($minutes * 60000) + ($seconds * 1000) + $milliseconds);
        }

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => data_get($file, 'id'),
            'name'                   => pathinfo(data_get($file, 'name', ''), PATHINFO_FILENAME),
            'mime_type'              => $mimeType,
            'type'                   => $type,
            'duration'               => $duration ?? null,
            'extension'              => $extension,
            'slug'                   => str()->slug($file['name']),
            'created_time'           => isset($file['createdOn'])
                ? Carbon::parse($file['createdOn'])->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public function getMetadataAttributes(?array $properties): array
    {
        $metadata = [];

        if ($campaignId = data_get($properties, 'campaign_id')) {
            $metadata['campaign_id'] = $campaignId;
        }

        if ($adId = data_get($properties, 'ad_id')) {
            $metadata['ad_id'] = $adId;
        }

        return $metadata;
    }

    public function listFolderContent(?array $request): iterable
    {
        $folderId = $request['folder_id'] ?? null;

        // List all the files in the current dir
        $this->handleTokenExpiration();

        if (! $folderId || $folderId === 'root') {
            try {
                $response = $this->client->get(config('peach.query_base_url') . '/campaigns', [
                    'headers' => [
                        'Accept'        => 'application/json',
                        'Authorization' => 'Bearer ' . $this->service->access_token,

                    ],
                ]);

                $body = json_decode($response->getBody()->getContents(), true);
                $campaigns = $body['data']['campaigns'];

                $newResponse = [];

                foreach ($campaigns as $campaign) {
                    $newResponse[] = [
                        'isDir'        => true,
                        'id'           => $campaign['id'],
                        'thumbnailUrl' => null,
                        'name'         => $campaign['reference'],
                        'metadata'     => $campaign,
                        ...$campaign,
                    ];
                }

                return $newResponse;
            } catch (Exception $e) {
                $this->log($e->getMessage(), 'error');
            }

            return [];
        }

        return $this->getFilesInFolder((string) $folderId);
    }

    public function getFilesInFolder(?string $folderId = 'root'): iterable
    {
        // Return all assets in folder
        try {
            // Get campaigns
            $adList = [];
            $response = $this->client->get(config('peach.query_base_url') . '/campaigns/' . $folderId . '/ads', [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $this->service->access_token,

                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $adList = $body['data']['ads'];
        } catch (GuzzleException|Exception $e) {
            $this->log('Error getting Peach campaigns: ' . $e->getMessage(), 'error');

            return $adList;
        }

        // Get all assets associated with ad
        $adAssets = [];

        if ($adList) {
            foreach ($adList as $ad) {
                try {
                    $response = $this->client->request('GET', config('peach.query_base_url') . '/ads/' . $ad['id'] . '/assets', [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $this->service->access_token,
                            'Accept'        => 'application/json',
                        ],
                    ]);

                    $body = json_decode($response->getBody()->getContents(), true);

                    if ($body) {
                        $assets = $body['data']['assets'];

                        foreach ($assets as $asset) {
                            if ($asset['status'] == 'Accepted') {
                                $asset['campaign_id'] = $ad['campaignId'];
                                $asset['duration'] = $ad['duration'];
                                $asset['ad_id'] = data_get($ad, 'id');
                                $adAssets[] = $asset;
                            }
                        }
                    }
                } catch (GuzzleException|Exception $e) {
                    $this->log('Error getting assets for Peach Ad ID' . $ad['id'] . ': ' . $e->getMessage(), 'error');
                }
            }
        }

        // Get asset info
        $assetItems = [];

        foreach ($adAssets as $asset) {
            try {
                $response = $this->client->request('GET', config('peach.query_base_url') . '/assets/' . $asset['id'], [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->service->access_token,
                        'Accept'        => 'application/json',
                    ],
                ]);
                $body = json_decode($response->getBody()->getContents(), true);

                if ($body) {
                    $body['duration'] = $asset['duration'];
                    $body['campaign_id'] = data_get($asset, 'campaign_id');
                    $body['ad_id'] = data_get($asset, 'ad_id');
                    $assetItems[] = $body;
                }
            } catch (GuzzleException|Exception $e) {
                $this->log('Error getting asset from Peach: ' . $e->getMessage());

                continue;
            }
        }

        $newResponse = [];

        foreach ($assetItems as $item) {
            $restOfTheItems = json_decode(json_encode($item), true);

            $newResponse[] = [
                'isDir'        => false,
                'name'         => $item['name'],
                'id'           => $item['id'],
                'thumbnailUrl' => '',
                ...$restOfTheItems,
            ];
        }

        return $newResponse;
    }

    public function listFolderSubFolders(?array $request): iterable
    {
        // Get all the folders and folder files
        $files = [];

        if (isset($request['folder_ids'])) {
            foreach ($request['folder_ids'] as $folderId) {
                $files = $this->getFilesInFolder((string) $folderId);
            }
        }

        return $files;
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Peach settings are required');
        abort_if(count(config('peach.settings')) !== $settings->count(), 406, 'All Settings must be present');

        $clientIdPattern = '/^[a-z0-9]{25,}$/i';
        $clientSecretPattern = '/^[a-zA-Z0-9]{50,}$/i'; // Adjust this based on actual requirements

        $clientId = $settings->firstWhere('name', 'PEACH_CLIENT_ID')?->payload ?? '';
        $clientSecret = $settings->firstWhere('name', 'PEACH_CLIENT_SECRET')?->payload ?? '';

        abort_unless(preg_match($clientIdPattern, $clientId), 406, 'Looks like your client ID format is invalid');
        abort_unless(preg_match($clientSecretPattern, $clientSecret), 406, 'Looks like your client secret format is invalid');

        return true;
    }

    private function storeToken($body): array
    {
        $this->accessToken = $body['access_token'];
        $expires = now()->addseconds($body['expires_in'])->getTimestamp();

        return [
            'access_token'  => $body['access_token'],
            'token_type'    => $body['token_type'],
            'expires'       => $expires,
            'token'         => $body,
            'refresh_token' => $body['refresh_token'],
        ];
    }
}
