<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Youtube;

use Exception;
use Throwable;
use Google\Client;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use RuntimeException;
use YoutubeDl\Options;
use YoutubeDl\YoutubeDl;
use Carbon\CarbonInterval;
use Illuminate\Support\Arr;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use MariusCucuruz\DAMImporter\Jobs\PostToVulcanJob;
use Illuminate\Support\Carbon;
use Google\Service\YouTube as YT;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Database\Eloquent\Collection;
use Google\Service\YouTube\VideoListResponse;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotQuery;
use MariusCucuruz\DAMImporter\Interfaces\HasRenditions;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Interfaces\HasDateRangeFilter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;

class Youtube extends SourceIntegration implements CanPaginate, HasDateRangeFilter, HasFolders, HasMetadata, HasRenditions, HasSettings, IsTestable
{
    private Client $client;

    private YT $youtube;

    public function initialize(): void
    {
        $settingsArray = $this->getSettings();

        $authConfig = $settingsArray['authConfig'];
        $redirectUri = $settingsArray['redirectUri'];

        $this->client = new Client;
        $this->client->setAuthConfig($authConfig);
        $this->client->setRedirectUri($redirectUri);
        $this->youtube = new YT($this->client);
        $this->client->setScopes([YT::YOUTUBE_READONLY]);
        $this->client->setAccessType(config('youtube.access_type'));
        $this->client->setApprovalPrompt(config('youtube.approval_prompt'));
        $this->client->setPrompt(config('youtube.prompt'));

        if ($this->service) {
            $this->getTokens([
                'access_token'  => $this->service->access_token,
                'refresh_token' => $this->service->refresh_token,
            ]);
        }
    }

    public function getSettings(?array $customKeys = []): array
    {
        $settings = parent::getSettings($customKeys);

        $authConfig = isset($settings['YOUTUBE_PROJECT_ID'], $settings['YOUTUBE_CLIENT_ID'], $settings['YOUTUBE_CLIENT_SECRET']) ? [
            'project_id'    => $settings['YOUTUBE_PROJECT_ID'],
            'client_id'     => $settings['YOUTUBE_CLIENT_ID'],
            'client_secret' => $settings['YOUTUBE_CLIENT_SECRET'],
        ] : config('youtube');

        $redirectUri = config('youtube.redirect_uri');

        return compact('authConfig', 'redirectUri');
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        // Callback from OAuth URL.
        if (data_get($tokens, 'access_token')) {
            $this->client->setAccessToken($tokens);
            $this->handleTokenExpiration();

            return new TokenDTO($tokens);
        }

        $code = request('code');

        if (empty($code)) {
            $this->log('Code not found in request', 'error');

            throw new CouldNotGetToken('Code not found in request');
        }

        try {
            $tokens = $this->client->fetchAccessTokenWithAuthCode($code);

            if (isset($tokens['error'])) {
                $this->log($tokens['error'], 'error');

                $this->redirectTo(config('youtube.redirect_uri'));
            }

            $this->client->setAccessToken($tokens);
            $this->handleTokenExpiration();

            $this->service?->update([
                'access_token'  => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
            ]);

            return new TokenDTO($this->client->getAccessToken());
        } catch (Exception $e) {
            $this->log("Error during token retrieval: {$e->getMessage()}", 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    public function getUser(): ?UserDTO
    {
        // Get the user information from YouTube. Using the channel title as channel email not available
        $queryParams = ['mine' => true];

        try {
            $response = $this->youtube->channels->listChannels('snippet,contentDetails,statistics', $queryParams);

            $email = data_get($response, 'items.0.snippet.title');
            $photo = data_get($response, 'items.0.snippet.thumbnails.high.url');

            throw_unless(filled($email), CouldNotQuery::class, 'Email not found in response.');

            return new UserDTO(compact('email', 'photo'));
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return new UserDTO;
        }
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $this->settings = $settings;

        $state = $this->generateRedirectOauthState();

        $this->client->setState($state);

        $authUrl = $this->client->createAuthUrl();

        $this->redirectTo($authUrl);
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        $queueId = config('services.vulcan.queue');

        dispatch(new PostToVulcanJob(
            $file,
            $queueId,
        ));

        return true;
    }

    public function setSizeFromFile(File $file, $outputFilePath): bool
    {
        if (! file_exists($outputFilePath)) {
            return false;
        }

        $size = filesize($outputFilePath);
        $file->size = $size;

        return $file->save();
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless(isset($file->remote_service_file_id), CouldNotDownloadFile::class, 'File id is not set');

        $this->initialize();

        $handle = null;
        $outputFilePath = null;

        try {
            $videoId = $file->remote_service_file_id;
            $videoUrl = "https://www.youtube.com/watch?v={$videoId}";
            $key = $this->prepareFileName($file);
            $uploadId = $this->createMultipartUpload($key);

            throw_unless($uploadId, CouldNotDownloadFile::class, 'Failed to initialize multi-part upload');

            $chunkSizeBytes = config('manager.chunk_size', 5) * 1024 * 1024;
            $chunkStart = 0;
            $partNumber = 1;
            $parts = [];

            $yt = new YoutubeDl(new ProcessBuilder);
            $yt->setOptions([
                'output'            => "{$file->id}.mp4",
                'format'            => 'bestvideo+bestaudio/best',
                'mergeOutputFormat' => 'mp4',
                'url'               => $videoUrl,
            ]);

            $yt->download(Options::create()->url($videoUrl)->downloadPath(storage_path('app')));

            $outputFilePath = storage_path("app/{$file->id}.mp4");

            $handle = fopen($outputFilePath, 'r');

            while (! feof($handle)) {
                $chunkData = fread($handle, $chunkSizeBytes);

                if ($chunkData === false) {
                    throw new RuntimeException('Error reading file chunk');
                }

                $parts[] = $this->uploadPart($key, $uploadId, $partNumber++, $chunkData);
                $chunkStart += $chunkSizeBytes;
            }

            $this->cleanupTemporaryFile($outputFilePath, $handle);

            $completeStatus = $this->completeMultipartUpload($key, $uploadId, $parts);

            throw_unless($completeStatus, CouldNotDownloadFile::class, 'Failed to complete multi-part upload');

            return $completeStatus;
        } catch (Throwable $e) {
            $this->log("Failed to download file: {$e->getMessage()}", 'error', null, $e->getTrace());
        } finally {
            $this->cleanupTemporaryFile($outputFilePath, $handle);
        }

        return false;
    }

    public function downloadFromService(File $file): bool|StreamedResponse|BinaryFileResponse
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');
        $tempDir = storage_path('app/temp');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($tempDir);

        try {
            $outputFilePath = $this->downloadVideo($file);
            $response = response()
                ->download($outputFilePath)
                ->deleteFileAfterSend();

            $response->headers->set('Content-Type', $file->mime_type);
            $response->headers->set('Content-Disposition',
                'attachment; filename="' . $file->slug . '.' . $file->extension . '"');
            $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
            $response->headers->set('Pragma', 'public');

            return $response;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return false;
    }

    public function downloadRendition(File $file): string|bool
    {
        return $this->downloadFile($file);
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $thumbnailPath = data_get($file, 'snippet.thumbnails.high.url');
        $duration = data_get($file, 'fileDetails.durationMs');

        if (! $duration) {
            $contentDuration = data_get($file, 'contentDetails.duration');
            $duration = $contentDuration ? CarbonInterval::make($contentDuration)->totalMilliseconds : null;
        }

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => $this->uniqueFileId($file, 'file_id'),
            'name'                   => data_get($file, 'snippet.title'),
            'thumbnail'              => $thumbnailPath,
            'mime_type'              => 'video/mp4',
            'type'                   => 'video',
            'extension'              => 'mp4',
            'resolution'             => isset($file['snippet']['thumbnails']['width'], $file['snippet']['thumbnails']['height'])
                ? "{$file['snippet']['thumbnails']['width']}x{$file['snippet']['thumbnails']['height']}"
                : null,
            'duration'     => $duration ?? null,
            'slug'         => str()->slug(data_get($file, 'snippet.title')),
            'created_time' => $file['snippet']['publishedAt']
                ? Carbon::parse($file['snippet']['publishedAt'])->format('Y-m-d H:i:s')
                : null,
            'remote_page_identifier' => data_get($file, 'snippet.channelId'),
        ]);
    }

    public function uploadThumbnail(mixed $file): string
    {
        $thumbnailUrl = data_get($file, 'thumbnail');

        if (! $thumbnailUrl) {
            return '';
        }

        $thumbnailPath = Path::join(
            config('manager.directory.thumbnails'),
            $file['id'],
            str()->slug($file['id']) . '.jpg'
        );

        $this->storage->put($thumbnailPath, file_get_contents($thumbnailUrl));

        return $thumbnailPath;
    }

    /** @deprecated  */
    public function listFolderSubFolders(?array $request): iterable
    {
        $items = [];
        $playlistIds = data_get($request, 'folder_ids', []);

        foreach ($playlistIds as $playlistId) {
            $items = [...$items, ...$this->getFilesInFolder($playlistId)];
        }

        return $items;
    }

    public function listFolderContent(?array $request): iterable
    {
        // Return playlists for modal to display
        $playlistId = data_get($request, 'folder_id') ?? 'root';

        // Do not want to display individual videos in modal, only playlists
        if ($playlistId !== 'root') {
            return [];
        }

        $newResponse = [
            [
                'id'    => 'allvideos',
                'isDir' => true,
                'name'  => 'All Videos',
            ],
        ];
        $nextPageToken = null;

        while (true) {
            try {
                $response = $this->youtube->playlists->listPlaylists('snippet', ['mine' => true, 'pageToken' => $nextPageToken, 'maxResults' => config('youtube.per_page')]);
            } catch (Exception $e) {
                $this->log($e->getMessage(), 'error');

                return $newResponse;
            }

            if ($items = data_get($response, 'items')) {
                foreach ($items as $item) {
                    $newResponse[] = [
                        'id'           => $item['id'],
                        'isDir'        => true,
                        'thumbnailUrl' => $item['snippet']['thumbnails']['default']['url'],
                        'name'         => $item['snippet']['title'],
                        ...json_decode(json_encode($item), true),
                    ];
                }
            }

            if (! $nextPageToken = data_get($response, 'nextPageToken')) {
                break;
            }
        }

        return $newResponse;
    }

    public function getFilesInFolder(?string $folderId = 'root'): iterable
    {
        $files = [];
        $nextPageToken = null;

        while (true) {
            try {
                $response = $this->youtube->playlistItems->listPlaylistItems('snippet', [
                    'playlistId' => $folderId,
                    'pageToken'  => $nextPageToken,
                    'maxResults' => config('youtube.per_page'),
                ]);
            } catch (Exception $e) {
                $this->log($e->getMessage(), 'error');

                return $files;
            }

            $items = data_get($response, 'items', []);

            foreach ($items as $item) {
                $restOfTheItems = json_decode(json_encode($item), true);
                unset($restOfTheItems['id']); // we need to remove this because it's not a valid ID for videos and returns playlist ID.
                $files[] = [
                    'isDir'        => false,
                    'name'         => $item['snippet']['title'],
                    'id'           => $item['snippet']['resourceId']['videoId'],
                    'thumbnailUrl' => $item['snippet']['thumbnails']['default']['url'],
                    ...$restOfTheItems,
                ];
            }

            if (! $nextPageToken = data_get($response, 'nextPageToken')) {
                break;
            }
        }

        return $files;
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'YouTube settings are required');
        abort_if(count(config('youtube.settings')) !== $settings->count(), 406, 'All Settings must be present');

        // Updated pattern to match client IDs with 10 to 15 digits at the start
        $clientIdPattern = '/\d{10,15}-[\w-]+\.apps\.googleusercontent\.com$/';
        $clientSecretPattern = '/^[a-zA-Z0-9-_]{24,}$/';

        $clientId = $settings->firstWhere('name', 'YOUTUBE_CLIENT_ID')?->payload ?? '';
        $clientSecret = $settings->firstWhere('name', 'YOUTUBE_CLIENT_SECRET')?->payload ?? '';

        abort_unless(preg_match($clientIdPattern, $clientId), 406, 'Looks like your client ID format is invalid');
        abort_unless(preg_match($clientSecretPattern, $clientSecret), 406, 'Looks like your client secret format is invalid');

        try {
            $this->client->setClientId($clientId);
            $this->client->setClientSecret($clientSecret);
            $authUrl = $this->client->createAuthUrl();
            $authUrlPattern = '/^https:\/\/accounts\.google\.com\/o\/oauth2\/(auth|v2\/auth)\?/';

            abort_unless(preg_match($authUrlPattern, $authUrl), 406, 'Looks like your auth URL format is invalid');

            return true;
        } catch (Exception $e) {
            abort(500, $e->getMessage());
        }
    }

    public function downloadVideo(File $file, ?string $rendition = null): string
    {
        $videoId = $file->remote_service_file_id;
        $videoUrl = "https://www.youtube.com/watch?v={$videoId}";
        $tempDir = storage_path('app');
        $processBuilder = new ProcessBuilder;
        $yt = new YoutubeDl($processBuilder);
        $yt->download(
            Options::create()
                ->output($file->id)
                ->mergeOutputFormat('mp4')
                ->videoMultistreams(true)
                ->downloadPath($tempDir)
                ->url($videoUrl)
        );

        return Path::join($tempDir, $file->id . '.mp4');
    }

    public function getMetadataAttributes(?array $properties): array
    {
        return data_get($properties, 'snippet') ?? $properties;
    }

    private function handleTokenExpiration(): void
    {
        if ($this->client->isAccessTokenExpired()) {
            if ($this->client->getRefreshToken()) {
                $cred = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());

                if ($this->service && data_get($cred, 'error')) {
                    $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
                }

                if (! empty($cred['access_token'])) {
                    $this->service->access_token = $cred['access_token'];
                    $this->service->expires = $cred['expires_in'];
                    $this->service->save();
                    $this->client->setAccessToken($cred);
                }
            } else {
                $this->service->update(['status' => IntegrationStatus::UNAUTHORIZED]);
            }
        }
    }

    public function paginate(array $request = []): void
    {
        $folders = data_get($request, 'metadata') ?? [];

        if (empty($folders)) {
            $this->getAllFiles();

            return;
        }

        foreach ($folders as $folder) {
            $id = data_get($folder, 'folder_id');
            $startDateInput = data_get($folder, 'start_time');
            $endDateInput = data_get($folder, 'end_time');

            if (! empty($startDateInput) && ! empty($endDateInput))) {
                $this->log("Invalid date range for folder ID: {$id}", 'error');

                continue;
            }

            if ($id == 'allvideos') {
                $this->getAllFiles();

                return;
            }

            $this->getFilesFromPlaylist($id);
        }
    }

    public function getFilesFromPlaylist($playlistId = null): void
    {
        if (! $playlistId) {
            return;
        }

        $nextPageToken = null;

        while (true) {
            $videos = [];

            try {
                $response = $this->youtube->playlistItems->listPlaylistItems('snippet', ['playlistId' => $playlistId, 'pageToken' => $nextPageToken, 'maxResults' => config('youtube.per_page')]);
            } catch (Exception $e) {
                $this->log($e->getMessage(), 'error');

                return;
            }

            $items = data_get($response, 'items', []);
            $videoIds = [];

            foreach ($items as $item) {
                $videoIds[] = data_get($item, 'snippet.resourceId.videoId');
            }

            $videoItems = $this->listVideos(['id' => implode(',', array_filter($videoIds))]);

            foreach ($videoItems as $item) {
                if ($this->isDateSyncFilter && ! $this->isWithinDatePeriod(data_get($item, 'snippet.publishedAt'))) {
                    continue;
                }

                if ($item?->getStatus()->getPrivacyStatus() === 'private') {
                    continue;
                }

                $itemArray = json_decode(json_encode($item), true);
                unset($itemArray['id']); // we need to remove this because it's not a valid ID for videos and returns playlist ID.
                $videos[] = [
                    'file_id' => $item->getId(),
                    ...$itemArray,
                ];
            }

            $this->dispatch($videos, $playlistId);

            if (! $nextPageToken = data_get($response, 'nextPageToken')) {
                break;
            }
        }
    }

    public function getAllFiles(): void
    {
        $nextPageTokenChannel = null;

        while (true) {
            $videos = [];
            $params = [
                'forMine'    => true,
                'maxResults' => config('youtube.per_page'), // Maximum allowed value 50
                'type'       => 'video',
                'pageToken'  => $nextPageTokenChannel,
            ];

            try {
                $channelQuery = $this->listSearch($params);
            } catch (Exception $e) {
                $this->log($e->getMessage(), 'error');

                break;
            }
            $items = data_get($channelQuery, 'items', []);
            $nextPageTokenChannel = data_get($channelQuery, 'nextPageToken');

            if (! $items) {
                break;
            }

            $videoIds = Arr::pluck($items, 'id.videoId') ?? [];

            /** @var VideoListResponse<\Google\Service\YouTube\Video> $videoItems */
            $videoItems = $this->listVideos(['id' => implode(',', $videoIds)]);

            /** @var \Google\Service\YouTube\Video $item */
            foreach ($videoItems as $item) {
                if ($this->isDateSyncFilter && ! $this->isWithinDatePeriod($item->getSnippet()->getPublishedAt())) {
                    continue;
                }

                if ($item?->getStatus()->getPrivacyStatus() === 'private') {
                    continue;
                }

                $restOfTheItems = json_decode(json_encode($item), true);
                $videos[] = [
                    'file_id' => $item->getId(),
                    ...$restOfTheItems,
                ];
            }

            $this->dispatch($videos, 'root');
            unset($videos);

            if (! $nextPageTokenChannel) {
                break;
            }
        }
    }

    public function listSearch($params = []): \Google\Service\YouTube\SearchListResponse
    {
        return $this->youtube->search->listSearch('snippet', $params);
    }

    public function listVideos($videoIds = []): array
    {
        return $this->youtube->videos->listVideos('snippet,contentDetails,fileDetails, status', $videoIds)['items'];
    }
}
