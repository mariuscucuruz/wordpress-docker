<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sftp;

use Exception;
use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use Illuminate\Support\Carbon;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use phpseclib3\Crypt\PublicKeyLoader;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Support\LazyCollection;
use phpseclib3\Net\SFTP as SFTPNetwork;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\IsTestable;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;

class Sftp extends SourceIntegration implements CanPaginate, HasFolders, HasSettings, IsTestable
{
    public const string NET_SFTP_TYPE_REGULAR = 'NET_SFTP_TYPE_REGULAR';

    public const string NET_SFTP_TYPE_DIRECTORY = 'NET_SFTP_TYPE_DIRECTORY';

    protected SFTPNetwork $sftp;

    public function getSettings(?array $customKeys = []): array
    {
        $settings = parent::getSettings($customKeys);

        if (empty($settings)) {
            return [];
        }

        return [
            'host'        => $settings['SFTP_HOST'],
            'port'        => $settings['SFTP_PORT'] ?? config('sftp.port', 22),
            'username'    => $settings['SFTP_USERNAME'],
            'password'    => $settings['SFTP_PASSWORD'],
            'public_key'  => $settings['SFTP_PUBLIC_KEY'] ?? null,
            'private_key' => $settings['SFTP_PRIVATE_KEY'] ?? null,
            'passphrase'  => $settings['SFTP_PASSPHRASE'] ?? null,
            'timeout'     => config('sftp.timeout'),
        ];
    }

    public function initialize()
    {
        return rescue(function () {
            return retry(3, function () {
                $settings = $this->getSettings();

                $this->sftp = new SFTPNetwork($settings['host'], $settings['port'], $settings['timeout']);

                if (isset($settings['private_key'], $settings['public_key'])) {
                    throw_unless(
                        $settings['public_key'] === $this->sftp->getServerPublicHostKey(),
                        'Host key verification failed'
                    );

                    $key = PublicKeyLoader::load($settings['private_key'], $settings['passphrase'] ?? null);

                    throw_unless(
                        $this->sftp->login($settings['username'], $key),
                        'Login failed with public key authentication.'
                    );
                }

                throw_unless(
                    $this->sftp->login($settings['username'], $settings['password']),
                    'Login failed with password authentication.'
                );

                return $this->sftp;
            }, 100);
        },
            function (Exception $e) {
                $this->log('SFTP Connection Error: ' . $e->getMessage(), 'error');

                return null;
            });
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $authUrl = Path::join(config('app.url'), 'sftp-redirect');

        if (isset($settings) && $settings->count()) {
            $authUrl .= '?' . http_build_query([
                'state' => json_encode(['settings' => $this->settings->pluck('id')?->toArray()]),
            ]);
        }

        $this->redirectTo($authUrl);
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        $settingsKeys = array_keys(config('sftp.settings'));

        $settings = collect($settingsKeys)
            ->mapWithKeys(fn ($key) => [$key => $this->settings->firstWhere('name', $key)?->payload])
            ->toArray();

        return new TokenDTO([
            'email'         => auth()->user()?->email,
            'api_key'       => $settings['SFTP_HOST'],
            'access_token'  => $settings['SFTP_USERNAME'],
            'refresh_token' => $settings['SFTP_PASSWORD'],
            'expires'       => null,
            'created'       => now(),
            'options'       => $this->settings->toJson(),
            'password'      => $settings['SFTP_PASSPHRASE'],
        ]);
    }

    public function getUser(): ?UserDTO
    {
        $this->settings->load('user');
        $username = $this->settings->firstWhere('name', 'SFTP_USERNAME');

        return new UserDTO([
            'email' => $username?->user?->email ?? $username?->payload,
            'photo' => $username?->user?->getOriginal('profile_photo_path'),
        ]);
    }

    /**
     * @throws \Throwable
     */
    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        try {
            // NOTE: `phpseclib` package handles chunking automatically for large files so no need to chunk here:
            $result = $this->sftp->get($file->remote_service_file_id);
            $path = $this->storeDataAsFile($result, $this->prepareFileName($file));

            throw_unless($result, CouldNotDownloadFile::class, 'Failed to download file from SFTP');
        } catch (CouldNotDownloadFile|Exception $e) {
            $this->log("Could not download file: {$e->getMessage()}", 'error', null, $e->getTrace());

            return false;
        }

        return $path;
    }

    /**
     * @throws \Throwable
     */
    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        try {
            $key = $this->prepareFileName($file);
            $uploadId = $this->createMultipartUpload($key, $file->mime_type);

            throw_unless($uploadId, CouldNotDownloadFile::class, 'Failed to initialize multi-part upload');

            // NOTE: `phpseclib` package handles chunking automatically for large files so no need to chunk here:
            $fileContent = $this->sftp->get($file->remote_service_file_id);

            throw_unless($fileContent, CouldNotDownloadFile::class, 'Failed to download file from SFTP');

            $part = $this->uploadPart($key, $uploadId, 1, $fileContent);

            $completeStatus = $this->completeMultipartUpload($key, $uploadId, [$part]);

            throw_unless($completeStatus, CouldNotDownloadFile::class, 'Failed to complete multi-part upload');

            return $completeStatus;
        } catch (CouldNotDownloadFile|Throwable|Exception $e) {
            $this->log("Could not download file: {$e->getMessage()}", 'error', null, $e->getTrace());
        }

        return false;
    }

    /**
     * @throws \Throwable
     */
    public function downloadFromService(File $file): StreamedResponse|BinaryFileResponse|bool
    {
        throw_unless($file->remote_service_file_id, CouldNotDownloadFile::class, 'File id is not set');

        try {
            $filePath = $file->remote_service_file_id;

            // NOTE: `phpseclib` package handles chunking automatically for large files so no need to chunk here:
            $fileContent = $this->sftp->get($filePath);

            throw_unless($fileContent, CouldNotDownloadFile::class, 'Error reading file from SFTP server.');

            $response = response($fileContent);

            $response->headers->set('Content-Type', $file->mime_type);
            $response->headers->set('Content-Disposition',
                'attachment; filename="' . $file->name . '.' . $file->extension . '"');
            $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
            $response->headers->set('Pragma', 'public');

            return $response;
        } catch (CouldNotDownloadFile|Throwable|Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return false;
    }

    public function uploadThumbnail(mixed $file): string
    {
        return '';
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = false): FileDTO
    {
        $fileName = (string) data_get($file, 'name');
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => data_get($file, 'id'),
            'name'                   => $name,
            'mime_type'              => $this->getMimeTypeOrExtension($extension) ?? null,
            'type'                   => dirname($this->getMimeTypeOrExtension($extension) ?? '') ?? null,
            'extension'              => $extension,
            'size'                   => data_get($file, 'size'),
            'slug'                   => str()->slug($name) ?? null,
            'created_time'           => transform(
                data_get($file, 'atime'),
                fn ($atime) => Carbon::parse($atime)->format('Y-m-d H:i:s')
            ),
            'modified_time' => transform(
                data_get($file, 'mtime'),
                fn ($mtime) => Carbon::parse($mtime)->format('Y-m-d H:i:s')
            ),

        ]);
    }

    public function listFolderContent(?array $request): iterable
    {
        return LazyCollection::make(fn () => yield from $this->getFilesInFolder($request['folder_id']));
    }

    public function listFolderSubFolders(?array $request): iterable
    {
        return LazyCollection::make(function () use ($request) {
            if (isset($request['folder_ids'])) {
                foreach ($request['folder_ids'] as $folderId) {
                    yield from $this->getFilesInFolder(folderId: $folderId, recursive: true);
                }
            }
        });
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'SFTP settings are required');

        $allSettings = config('sftp.settings');
        $requiredSettings = array_filter(
            array_keys($allSettings),
            fn ($key) => empty($allSettings[$key]['optional']) || $allSettings[$key]['optional'] === false
        );

        foreach ($requiredSettings as $setting) {
            abort_unless($settings->contains('name', $setting), 406, "Missing required setting: {$setting}");
        }

        $sftpHost = $settings->firstWhere('name', 'SFTP_HOST')?->payload;
        $sftpPort = $settings->firstWhere('name', 'SFTP_PORT')?->payload;
        $sftpUsername = $settings->firstWhere('name', 'SFTP_USERNAME')?->payload;
        $sftpPassword = $settings->firstWhere('name', 'SFTP_PASSWORD')?->payload;

        $sftpPublicKey = $settings->firstWhere('name', 'SFTP_PUBLIC_KEY')?->payload;
        $sftpPrivateKey = $settings->firstWhere('name', 'SFTP_PRIVATE_KEY')?->payload;
        $sftpPassphrase = $settings->firstWhere('name', 'SFTP_PASSPHRASE')?->payload;

        try {
            $sftp = new SFTPNetwork($sftpHost, $sftpPort, config('sftp.timeout'));

            if ($sftpPublicKey && $sftpPrivateKey) {
                $key = PublicKeyLoader::load($sftpPrivateKey, $sftpPassphrase ?? null);

                abort_unless(
                    $sftp->login($sftpUsername, $key),
                    406,
                    'SFTP key-based authentication failed.'
                );
            } else {
                abort_unless(
                    $sftp->login($sftpUsername, $sftpPassword),
                    406,
                    'SFTP password-based authentication failed.'
                );
            }

            return true;
        } catch (Exception $e) {
            abort(500, 'SFTP connection test failed: ' . $e->getMessage());
        }
    }

    public function getFilesInFolder(?string $folderId = 'root', bool $recursive = false): iterable
    {
        if ($folderId === 'root' || ! $folderId) {
            $folderId = '/';
        }

        $currentPath = $folderId === '/' ? $folderId : rtrim($folderId, '/') . '/';
        $contents = $this->sftp->rawlist($currentPath);

        if (! $contents) {
            $this->log("Unable to list directory: {$folderId}", 'error');

            return;
        }

        foreach ($contents as $name => $attributes) {
            $nameStr = (string) $name;

            if (str_starts_with($nameStr, '.')) {
                continue;
            }

            $extension = strtolower(pathinfo($nameStr, PATHINFO_EXTENSION));
            $fullPath = $currentPath . $nameStr;

            if ($attributes['type'] === self::NET_SFTP_TYPE_DIRECTORY
                && ! in_array($nameStr, config('sftp.excluded_folders'))) {
                if ($recursive) {
                    yield from $this->getFilesInFolder($fullPath, true);
                } else {
                    yield [
                        'id'    => $fullPath,
                        'name'  => $nameStr,
                        'isDir' => true,
                        'path'  => $fullPath,
                        ...$attributes,
                    ];
                }
            } elseif ($attributes['type'] === self::NET_SFTP_TYPE_REGULAR
                && in_array($extension, config('manager.meta.file_extensions'))) {
                yield [
                    'id'    => $fullPath,
                    'name'  => $nameStr,
                    'isDir' => false,
                    'path'  => $fullPath,
                    ...$attributes,
                ];
            }
        }
    }

    public function paginate(?array $request = []): void
    {
        $this->initialize();

        if (isset($request['folder_ids'])) {
            foreach ($request['folder_ids'] as $folderId) {
                $files = iterator_to_array($this->getFilesInFolder(folderId: $folderId, recursive: true));
                $this->dispatch($files, (string) $folderId);
            }

            return;
        }

        $files = iterator_to_array($this->getFilesInFolder(folderId: DIRECTORY_SEPARATOR, recursive: true));
        $this->dispatch($files, 'root');
    }
}
