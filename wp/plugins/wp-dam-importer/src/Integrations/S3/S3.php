<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\S3;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use Aws\S3\S3Client;
use MariusCucuruz\DAMImporter\Support\Path;
use Aws\Iam\IamClient;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use MariusCucuruz\DAMImporter\DTOs\FileDTO;
use MariusCucuruz\DAMImporter\DTOs\UserDTO;
use MariusCucuruz\DAMImporter\DTOs\TokenDTO;
use Illuminate\Database\Eloquent\Collection;
use MariusCucuruz\DAMImporter\SourceIntegration;
use MariusCucuruz\DAMImporter\Interfaces\HasFolders;
use MariusCucuruz\DAMImporter\Interfaces\CanPaginate;
use MariusCucuruz\DAMImporter\Interfaces\HasMetadata;
use MariusCucuruz\DAMImporter\Interfaces\HasSettings;
use MariusCucuruz\DAMImporter\Pagination\PaginationType;
use MariusCucuruz\DAMImporter\Pagination\PaginatedResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use MariusCucuruz\DAMImporter\Interfaces\HasDateRangeFilter;
use MariusCucuruz\DAMImporter\Exceptions\InvalidSettingValue;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use MariusCucuruz\DAMImporter\Exceptions\CouldNotDownloadFile;
use Symfony\Component\HttpFoundation\File\File as FileUpload;

class S3 extends SourceIntegration implements CanPaginate, HasDateRangeFilter, HasFolders, HasMetadata, HasSettings
{
    protected function getPaginationType(): PaginationType
    {
        return PaginationType::Token;
    }

    protected function getRootFolders(): array
    {
        return [['id' => null]];
    }

    protected function fetchPage(?string $folderId, mixed $cursor, array $folderMeta = []): PaginatedResponse
    {
        throw_if(empty($this->bucketName), new InvalidArgumentException('Bucket name is required but not provided'));

        $response = $this->getS3BucketObjects($folderId, $cursor);

        $subfolders = collect(data_get($response, 'CommonPrefixes', []))
            ->map(fn ($folder) => ['id' => data_get($folder, 'Prefix')])
            ->toArray();

        $items = data_get($response, 'Contents', []);

        $nextCursor = data_get($response, 'IsTruncated')
            ? data_get($response, 'NextContinuationToken')
            : null;

        return new PaginatedResponse($items, $subfolders, $nextCursor);
    }

    protected function transformItems(array $items): array
    {
        throw_if(empty($this->bucketName), new InvalidArgumentException('Bucket name is required but not provided'));

        return collect($items)
            ->reject(fn ($file) => data_get($file, 'Size') == 0)
            ->map(fn ($file) => [
                ...$file,
                's3_bucket' => $this->bucketName,
                'extension' => pathinfo(data_get($file, 'Key'), PATHINFO_EXTENSION),
            ])
            ->values()
            ->toArray();
    }

    protected function getItemDate(array $item): mixed
    {
        return data_get($item, 'LastModified');
    }

    protected function getImportGroupName(?string $folderId, array $folderMeta, int $pageCount): string
    {
        $path = $folderId ?? '';

        return "{$this->bucketName}/{$path}_{$pageCount}";
    }

    public function paginate(?array $request = []): void
    {
        $folders = data_get($request, 'metadata', []);

        if (! empty($folders)) {
            collect($folders)->each(function ($folder) {
                $id = data_get($folder, 'folder_id');
                $startDateInput = data_get($folder, 'start_time');
                $endDateInput = data_get($folder, 'end_time');

                if (! empty($startDateInput) && ! empty($endDateInput)) {
                    $this->log("Invalid date range for folder ID: {$id}", 'error');

                    return;
                }

                $this->syncBucketFolder($id);
            });

            return;
        }

        $this->syncBucketFolder();
    }

    public function syncBucketFolder(?string $folder = null): void
    {
        $this->paginateFolder($folder, []);
    }

    public function dispatchFiles(array $files = [], $path = null, $count = 0): void
    {
        throw_if(empty($this->bucketName), new InvalidArgumentException('Bucket name is required but not provided'));

        $files = collect($files)
            ->reject(fn ($file) => data_get($file, 'Size') == 0)
            ->map(fn ($file) => [
                ...$file,
                's3_bucket' => $this->bucketName,
                'extension' => pathinfo(data_get($file, 'Key'), PATHINFO_EXTENSION),
                'path'      => "{$this->bucketName}/{$path}",
            ])
            ->values()
            ->toArray();

        if (filled($files)) {
            $this->dispatch($this->filterSupportedFileExtensions($files), "{$this->bucketName}/{$path}_{$count}");
        }
    }

    public ?string $accessKey = null;

    public ?string $secretAccessKey;

    public ?string $region;

    public ?string $bucketName;

    public ?S3Client $s3Client;

    public ?IamClient $iamClient;

    public function initialize(): void
    {
        $settings = $this->getSettings();
        $this->accessKey = data_get($settings, 'accessKey');
        $this->secretAccessKey = data_get($settings, 'secretAccessKey');
        $this->region = data_get($settings, 'region');
        $this->bucketName = data_get($settings, 'bucketName');

        $this->validateSettings();
        $this->initializeClients();
    }

    public function getSettings(?array $customKeys = []): array
    {
        $settings = parent::getSettings($customKeys);

        $accessKey = data_get($settings, 'S3_ACCESS_KEY');
        $secretAccessKey = data_get($settings, 'S3_SECRET_ACCESS_KEY');
        $region = data_get($settings, 'S3_REGION');
        $bucketName = data_get($settings, 'S3_BUCKET_NAME');

        return compact('accessKey', 'secretAccessKey', 'region', 'bucketName');
    }

    public function validateSettings(): bool
    {
        throw_if(empty($this->accessKey), InvalidSettingValue::make('Access Key'), 'Access Key is missing!');
        throw_if(empty($this->secretAccessKey), InvalidSettingValue::make('Secret Access Key'), 'Secret Access Key is missing!');
        throw_if(! in_array($this->region, config('s3.aws_regions')), InvalidSettingValue::make('Region'), 'Region is not recognised!');
        throw_if(empty($this->bucketName), InvalidSettingValue::make('Bucket Name'), 'Bucket Name is missing!');
        throw_if($this->bucketName === config('s3.destination_s3_bucket'), InvalidSettingValue::make('Bucket Name'), 'Can not sync destination bucket!');

        return true;
    }

    public function initializeClients(): void
    {
        $this->s3Client = new S3Client([
            'version'     => config('s3.s3_client_version'),
            'region'      => $this->region,
            'credentials' => [
                'key'    => $this->accessKey,
                'secret' => $this->secretAccessKey,
            ],
        ]);

        $this->iamClient = new IamClient([
            'version'     => config('s3.iam_client_version'),
            'region'      => $this->region,
            'credentials' => [
                'key'    => $this->accessKey,
                'secret' => $this->secretAccessKey,
            ],
        ]);
    }

    public function redirectToAuthUrl(?Collection $settings = null, string $email = ''): void
    {
        $authUrl = Path::join(config('app.url'), config('s3.name') . '-redirect');

        if (isset($settings) && $settings->count()) {
            $authUrl .= '?' . Arr::query([
                'state' => [
                    'settings' => $this->settings->pluck('id')->toArray(),
                ],
            ]);
        }

        $this->redirectTo($authUrl);
    }

    public function getTokens(array $tokens = []): TokenDTO
    {
        return new TokenDTO([
            'access_token' => $this->accessKey,
            'expires'      => null,
            'created'      => now(),
        ]);
    }

    public function getUser(): ?UserDTO
    {
        try {
            $response = $this->iamClient->getUser();
            $user = data_get($response->toArray(), 'User');

            return new UserDTO([
                'email' => data_get($user, 'UserId'),
                'name'  => data_get($user, 'UserName'),
            ]);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
        }

        return new UserDTO;
    }

    public function listFolderContent(?array $request): array
    {
        // Todo: siteDriveId is OneDrive specific, we should refactor this to be meta
        $folderId = data_get($request, 'folder_id') === 'root' ? null : data_get($request, 'folder_id');
        $folders = [];
        $nextContinuationToken = null;

        do {
            $response = $this->getS3BucketObjects($folderId, $nextContinuationToken);
            $objects = data_get($response, 'CommonPrefixes', []);
            $folders = [
                ...$folders,
                ...collect($objects)->map(fn ($folder) => [
                    'id'     => data_get($folder, 'Prefix'),
                    'name'   => data_get($folder, 'Prefix'),
                    'isDir'  => true,
                    'siteId' => $this->bucketName,
                ])->values()->toArray(),
            ];
            $nextContinuationToken = data_get($response, 'NextContinuationToken', false);
        } while ($nextContinuationToken && data_get($response, 'IsTruncated'));

        return $folders;
    }

    public function getS3BucketObjects(?string $prefix = null, ?string $nextContinuationToken = null): array
    {
        // https://docs.aws.amazon.com/AmazonS3/latest/API/API_ListObjectsV2.html
        try {
            throw_if(empty($this->bucketName), new InvalidArgumentException('Bucket name is required but not provided'));

            $response = $this->s3Client->listObjectsV2([
                'Bucket'            => $this->bucketName,
                'Prefix'            => $prefix,
                'MaxKeys'           => config('s3.per_page'),
                'ContinuationToken' => $nextContinuationToken,
                'Delimiter'         => '/',
            ]);

            return $response->toArray();
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return [];
        }
    }

    public function downloadTemporary(File $file, ?string $rendition = null): string|bool
    {
        $key = $file->getMetaExtra('Key');

        throw_unless($key, CouldNotDownloadFile::class, 'No Key present.');
        throw_if(empty($this->bucketName), new InvalidArgumentException('Bucket name is required but not provided'));

        $tempFilePath = tempnam(sys_get_temp_dir(), config('s3.name') . '_');
        throw_unless(
            $tempFilePath,
            CouldNotDownloadFile::class,
            'Temporary file not found!'
        );

        $fp = fopen($tempFilePath, 'w');

        try {
            $this->s3Client->getObject([
                'Bucket' => $this->bucketName,
                'Key'    => $key,
                'SaveAs' => $fp,
            ]);

            $path = $this->storage::putFileAs(
                $this->getStoragePathForFile($file),
                new FileUpload($tempFilePath),
                $this->prepareFileName($file)
            );

            throw_if($path === false, CouldNotDownloadFile::class, 'Failed to initialize file storage');

            return $path;
        } catch (Exception $e) {
            $this->log("Error downloading file: {$e->getMessage()}", 'error', null, $e->getTrace());

            return false;
        } finally {
            $this->cleanupTemporaryFile($tempFilePath, $fp);
        }
    }

    public function downloadMultiPart(File $file, ?string $rendition = null): string|bool
    {
        return $this->downloadTemporary($file, $rendition);
    }

    public function downloadFromService(File $file): StreamedResponse|bool|BinaryFileResponse
    {
        $downloadUrl = $file->download_url;

        throw_unless($downloadUrl, CouldNotDownloadFile::class, 'No download url present.');

        return $this->handleDownloadFromService($file, $downloadUrl);
    }

    public function uploadThumbnail(mixed $file): ?string
    {
        return null;
    }

    public function fileProperties(mixed $file, array $attr, bool $createThumbnail = true): FileDTO
    {
        $key = data_get($file, 'Key');
        $name = pathinfo($key, PATHINFO_FILENAME);
        $extension = pathinfo($key, PATHINFO_EXTENSION);

        return new FileDTO([
            'user_id'                => data_get($attr, 'user_id'),
            'team_id'                => data_get($attr, 'team_id'),
            'service_id'             => data_get($attr, 'service_id'),
            'service_name'           => data_get($attr, 'service_name'),
            'import_group'           => data_get($attr, 'import_group'),
            'remote_service_file_id' => hash('sha256', $key),
            'size'                   => data_get($file, 'size'),
            'name'                   => $name,
            'thumbnail'              => null,
            'mime_type'              => $this->getMimeTypeOrExtension($extension),
            'type'                   => $this->getFileTypeFromExtension($extension),
            'extension'              => $extension,
            'slug'                   => str()->slug($name),
            'created_time'           => null,
            'modified_time'          => isset($file['LastModified'])
                ? Carbon::parse($file['LastModified'])->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public function getMetadataAttributes(?array $properties): array
    {
        try {
            throw_if(empty(data_get($properties, 'Key')), InvalidArgumentException::class, 'Key is required but not provided');
            throw_if(empty($this->bucketName), new InvalidArgumentException('Bucket name is required but not provided'));

            $response = $this->s3Client->headObject([
                'Bucket' => $this->bucketName,
                'Key'    => data_get($properties, 'Key'),
            ]);

            return $properties + $response->toArray();
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');

            return [];
        }
    }

    public function testSettings(Collection $settings): bool
    {
        abort_if($settings->isEmpty(), 400, 'S3 settings are required');
        abort_if(count(config('s3.settings')) !== $settings->count(), 406, 'All Settings must be present');

        return true;
    }
}
