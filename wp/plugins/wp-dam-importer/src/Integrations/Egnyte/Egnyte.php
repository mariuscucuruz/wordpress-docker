<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Egnyte;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotQuery;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotGetToken;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotInitializePackage;

class Egnyte extends SourceIntegration implements CanPaginate, HasFolders, HasMetadata, HasSettings, IsTestable
{
    public ?string $clientId;

    public ?string $clientSecret;

    public ?string $server;

    public ?string $queryBase;

    public ?string $accessToken;

    public function initialize(): void
    {
        $settings = $this->getSettings();

        $this->clientId = data_get($settings, 'clientId');
        $this->clientSecret = data_get($settings, 'clientSecret');
        $this->server = data_get($settings, 'server');
        $this->queryBase = 'https://' . $this->server . '.egnyte.com/';
    }

    public function getSettings($customKeys = null): array
    {
        $settings = parent::getSettings($customKeys);

        $clientId = $settings['EGNYTE_CLIENT_ID'] ?? config('egnyte.client_id');
        $clientSecret = $settings['EGNYTE_CLIENT_SECRET'] ?? config('egnyte.client_secret');
        $server = $settings['EGNYTE_SERVER_SUBDOMAIN'] ?? config('egnyte.server');

        return compact('clientId', 'clientSecret', 'server');
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $url = $this->queryBase . config('egnyte.oauth_base_suffix');
        $queryParams = [
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'redirect_uri'  => config('egnyte.redirect_uri'),
            'scope'         => config('egnyte.scope'),
            'state'         => json_encode(['settings' => $this->settings?->pluck('id')?->toArray()]),
        ];

        throw_unless(
            $this->clientId && $this->clientSecret && $this->server && config('egnyte.redirect_uri'),
            CouldNotInitializePackage::class,
            'Egnyte settings are required!'
        );

        $queryString = http_build_query($queryParams);
        $requestUrl = "{$url}?{$queryString}";

        $this->redirectTo($requestUrl);
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        try {
            $response = Http::timeout(config('queue.timeout'))->asForm()->post($this->queryBase . config('egnyte.oauth_base_suffix'), [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code'          => request('code'),
                'scope'         => config('egnyte.scope'),
                'redirect_uri'  => config('egnyte.redirect_uri'),
                'grant_type'    => 'authorization_code',
            ])->throw();

            $this->accessToken = data_get($response, 'access_token');

            return new TokenDTO([
                'access_token' => $this->accessToken,
                'expires'      => null,
                'created'      => now(),
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            throw new CouldNotGetToken($e->getMessage());
        }
    }

    public function getUser(): ?UserDTO
    {
        try {
            $response = Http::timeout(config('queue.timeout'))->withToken($this->accessToken)
                ->get($this->queryBase . config('egnyte.query_base_suffix') . '/userinfo')
                ->throw();

            $body = $response->collect();

            throw_if(data_get($body, 'email') === null && data_get($body, 'username') === null,
                CouldNotQuery::class, 'Neither name nor email found in the response');

            return new UserDTO([
                'email'   => data_get($body, 'email') ?? data_get($body, 'username'),
                'name'    => data_get($body, 'username'),
                'user_id' => data_get($body, 'id'),
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return new UserDTO;
        }
    }

    public function listFolderContent(?array $request): iterable
    {
        $path = data_get($request, 'folder_id') ?? '/';
        $folders = collect();
        $offset = 0;

        while (true) {
            $items = $this->getItemsFromPath($path, $offset);

            if ($items === false) {
                break;
            }
            $newFolders = collect(data_get($items, 'folders', []));
            $totalCount = (int) data_get($items, 'total_count', 0);

            if (filled($newFolders)) {
                $folders = $folders->merge($newFolders);
            }

            if ($offset >= $totalCount - 1) {
                break;
            }

            $offset += config('egnyte.count');
        }

        return $folders->map(fn ($folder) => [
            'id'    => data_get($folder, 'path'),
            'isDir' => data_get($folder, 'is_folder', true),
            'name'  => data_get($folder, 'name'),
        ])->values();
    }

    public function getItemsFromPath($path = null, $offset = 0): bool|iterable
    {
        if ($path == 'root' || $path === null) {
            $path = '/';
        }

        try {
            $response = Http::timeout(config('queue.timeout'))->withToken($this->service->access_token)
                ->retry(3, 1000)
                ->withQueryParameters([
                    'offset'       => $offset,
                    'count'        => config('egnyte.count'),
                    'sort_by'      => 'last_modified',
                    'list_content' => true,
                ])
                ->get($this->queryBase . config('egnyte.query_base_suffix') . '/fs' . $path)
                ->throw();

            return $response->collect();
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return false;
        }
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $name = data_get($file, 'name');
        $extension = $this->getFileExtensionFromFileName($name);
        $mimeType = $this->getMimeTypeOrExtension($extension);
        $type = $this->getFileTypeFromExtension($extension);

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => $this->uniqueFileId($file, 'entry_id'),
            'name'                   => $name,
            'mime_type'              => $mimeType,
            'type'                   => $type,
            'extension'              => $extension,
            'size'                   => data_get($file, 'size'),
            'slug'                   => str()->slug(pathinfo($file['name'], PATHINFO_FILENAME)),
            'created_time'           => $file['uploaded']
                ? Carbon::parse($file['uploaded'])->format('Y-m-d H:i:s')
                : null,
            'modified_time' => isset($file['last_modified'])
                ? Carbon::parse($file['last_modified'])->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'Egnyte settings are required');
        abort_if(count(config('egnyte.settings')) !== $settings->count(), 406, 'All Settings must be present');

        return true;
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set.');

        $tempFilePath = tempnam(sys_get_temp_dir(), config('egnyte.name') . '_');
        throw_unless($tempFilePath, CouldNotDownloadFile::class, 'Temporary file not found!');

        $fp = fopen($tempFilePath, 'w');

        $downloadUrl = $this->getDownloadUrl($file);
        $alternateAttempted = false;

        while (true) {
            try {
                $response = Http::timeout(config('queue.timeout'))->withToken($this->service->access_token)
                    ->retry(3, 1000)
                    ->get($downloadUrl);

                if ($response->failed()) {
                    $this->log('Failed to download file.', 'error', null, $file->toArray());

                    return false;
                }

                $this->downstreamToTmpFile($response->body());

                break;
            } catch (Exception $e) {
                if (! $alternateAttempted) {
                    $downloadUrl = $this->getDownloadUrl($file, true);
                    $alternateAttempted = true;
                } else {
                    $this->log("Error getting downloading File: {$e->getMessage()}", 'error', null, $e->getTrace());

                    return false;
                }
            }
        }

        $path = $this->downstreamToTmpFile(null, $this->prepareFileName($file));

        $fileSize = $this->getFileSize($path);

        $file->update(['size' => $fileSize]);

        $this->cleanupTemporaryFile($tempFilePath, $fp);

        return $path;
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        return $this->downloadTemporary($file, $rendition);
    }

    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        $downloadUrl = $this->getDownloadUrl($file);

        return $this->handleDownloadFromService($file, $downloadUrl, [
            'Authorization' => 'Bearer ' . $this->service->access_token,
        ]);
    }

    public function getDownloadUrl(File $file, $retry = false): string
    {
        $baseUrl = $this->queryBase . config('egnyte.query_base_suffix') . '/fs-content';
        $metadata = $file->getMetaExtra();
        $filePath = data_get($metadata, 'path');
        $groupId = data_get($metadata, 'group_id');

        if ($retry) {
            throw_unless($filePath, CouldNotDownloadFile::class, 'Download URL is not set.');

            return "{$baseUrl}{$filePath}";
        }

        throw_unless($groupId, CouldNotDownloadFile::class, 'Download URL is not set.');

        return "{$baseUrl}/ids/file/{$groupId}";
    }

    public function uploadThumbnail(mixed $file): ?string
    {
        return null;
    }

    public function paginate(array $request = []): void
    {
        $folders = data_get($request, 'folder_ids', []);

        if (empty($folders)) {
            $this->getFolderFilesAndDispatchJobs();
        }

        foreach ($folders as $folder) {
            $this->getFolderFilesAndDispatchJobs($folder);
        }
    }

    public function getFolderFilesAndDispatchJobs($path = null, $offset = 0): void
    {
        while (true) {
            $result = $this->getItemsFromPath($path, $offset);

            if (! $result) {
                break;
            }

            $totalCount = (int) data_get($result, 'total_count', 0);
            $folders = collect(data_get($result, 'folders', []));
            $files = data_get($result, 'files', []);

            $folders->each(fn ($folder) => $this->getFolderFilesAndDispatchJobs(data_get($folder, 'path')));

            $files = collect($files)->filter(fn ($file) => in_array(
                pathinfo(data_get($file, 'name'), PATHINFO_EXTENSION),
                config('manager.meta.file_extensions'))
            )->toArray();

            if (filled($files)) {
                $this->dispatch($files, $path);
            }

            if (($offset + config('egnyte.count')) >= $totalCount) {
                break;
            }

            $offset += config('egnyte.count');
        }
    }
}
