<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\CustomSource\Controllers;

use Exception;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Models\Team;
use MariusCucuruz\DAMImporter\Models\User;
use Aws\S3\S3Client;
use MariusCucuruz\DAMImporter\Enums\MetaType;
use MariusCucuruz\DAMImporter\Models\Service;
use MariusCucuruz\DAMImporter\Models\ImportGroup;
use Illuminate\Support\Str;
use MariusCucuruz\DAMImporter\Enums\IntegrationStatus;
use Illuminate\Http\Request;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Enums\ImportGroupStatus;
use Illuminate\Http\JsonResponse;
use MariusCucuruz\DAMImporter\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use MariusCucuruz\DAMImporter\Integrations\CustomSource\CustomSource;
use MariusCucuruz\DAMImporter\Traits\FileInformation;
use MariusCucuruz\DAMImporter\Services\StorageService;
use MariusCucuruz\DAMImporter\Integrations\CustomSource\Models\CustomSourceFile;
use MariusCucuruz\DAMImporter\Integrations\CustomSource\Models\CustomSourceToken;
use MariusCucuruz\DAMImporter\Integrations\CustomSource\Enums\CustomSourceFileEnum;
use MariusCucuruz\DAMImporter\Integrations\CustomSource\Enums\CustomSourceTypeEnum;
use MariusCucuruz\DAMImporter\Integrations\CustomSource\Enums\CustomSourceTokenEnum;

class CustomSourceController extends Controller
{
    // Test postman collection: https://speeding-firefly-381926.postman.co/workspace/New-Team-Workspace~7237fcc7-9ec3-4f68-b218-8f16b36ed2b4/collection/27427367-5bf5c768-ed1d-4af3-a3f6-e9c27b35a1a0?action=share&creator=27427367
    use FileInformation;

    public array $input = [];

    public ?S3Client $s3Client;

    public function __construct()
    {
        $this->s3Client = StorageService::getClient(); // @phpstan-ignore-line
    }

    public function createService(Request $request): RedirectResponse
    {
        $this->validate($request, [
            'settings.title'       => 'required|string|max:30',
            'settings.description' => 'nullable|string',
            'settings.type'        => 'nullable|string',
        ]);

        $name = data_get($request, 'settings.title');
        throw_unless($name, new Exception('CustomSource Name is required'));

        $service = Service::create([
            'user_id'        => auth()->id(),
            'email'          => auth()->user()->email,
            'interface_type' => CustomSource::class,
            'name'           => $name,
            'status'         => IntegrationStatus::ACTIVE,
            'photo'          => config('uploader.owner_photo'),
        ]);

        if ($description = data_get($request, 'settings.description')) {
            $service->saveMeta('description', $description);
        }

        $type = data_get($request, 'settings.type');

        if ($type && $typeEnum = CustomSourceTypeEnum::tryFrom(strtoupper(($type)))) {
            $service->saveMeta('type', $typeEnum->value);
        }

        if ($description = data_get($request, 'settings.description')) {
            $service->metas()->updateOrCreate([
                'key' => 'description',
            ], [
                'value' => $description,
            ]);
        }

        $type = data_get($request, 'settings.type');

        if ($type && $typeEnum = CustomSourceTypeEnum::tryFrom(strtoupper(($type)))) {
            $service->saveMeta('type', $typeEnum->value);
        }

        CustomSourceToken::create([
            'client_id'     => bin2hex(random_bytes(16)),
            'client_secret' => bin2hex(random_bytes(32)),
            'status'        => CustomSourceTokenEnum::ACTIVE,
            'service_id'    => $service->id,
            'user_id'       => auth()->id(),
        ]);

        return to_route('service.show.settings', $service);
    }

    public function storeFile(Request $request)
    {
        $request->validate([
            'client_id'     => 'required|string',
            'client_secret' => 'required|string',
            'name'          => 'required|string',
            'path'          => 'nullable|string',
        ]);

        $token = CustomSourceToken::where('client_id', $request->client_id)->first();

        if (! $token) {
            return response()->json(['error' => 'Client ID not found'], 400);
        }

        if ($request->client_secret !== $token->client_secret) {
            return response()->json(['error' => 'Incorrect client secret'], 401);
        }

        $ext = $this->getFileExtensionFromFileName($request->name);

        if (! in_array($ext, config('manager.meta.file_extensions'))) {
            return response()->json(['error' => 'File type is not supported'], 400);
        }

        $key = "customsource/{$request->client_id}/" . str()->random(5) . '-' . $request->name;
        $presignedUrl = $this->generateUrls($key, $ext);

        if (! $presignedUrl) {
            return response()->json(['error' => 'Failed to create presigned URL for file format: ' . $ext], 400);
        }

        CustomSourceFile::create([
            'token_id'          => $token->id,
            'status'            => CustomSourceFileEnum::PENDING,
            'presigned'         => $presignedUrl,
            'bucket'            => config('filesystems.disks.s3.bucket'),
            'key'               => $key,
            'path'              => $request->path ?? 'false',
            'original_filename' => $request->name,
            'service_id'        => $token->service_id,
        ]);

        return response()->json([
            'presigned_url'     => $presignedUrl,
            'url_expiration'    => now()->addhours(24),
            'file_key'          => $key,
            'callback_endpoint' => config('customsource.callback_endpoint'),
        ]);
    }

    public function generateUrls($fileKey, $ext): ?string
    {
        $contentType = $this->getContentType($ext);

        if (! $contentType) {
            return null;
        }

        $bucket = config('filesystems.disks.s3.bucket');
        $expiration = config('filesystems.expiration');

        $command = $this->s3Client->getCommand('PutObject', [
            'Bucket'      => $bucket,
            'Key'         => $fileKey,
            'ContentType' => $contentType,
        ]);

        return (string) $this->s3Client->createPresignedRequest($command, $expiration)->getUri();
    }

    public function getContentType($ext): ?string
    {
        return $this->getFileTypeFromExtension($ext) ? "{$this->getFileTypeFromExtension($ext)}/{$ext}" : null;
    }

    public function storeFileCallBack(Request $request): JsonResponse
    {
        // Client confirms status of upload, and if file is successfully uploaded
        // POST: client_id, client_secret, status, file_key
        $request->validate([
            'client_id'     => 'required|string',
            'client_secret' => 'required|string',
            'status'        => 'required|string',
            'file_key'      => 'required|string',
            'meta'          => 'sometimes|nullable|json',
        ]);

        $customFile = CustomSourceFile::where('key', $request->file_key)->first();

        if (! $customFile) {
            return response()->json([
                'error' => 'Custom File not found. Please create a Store File request first',
            ], 400);
        }

        if ($request->client_id !== $customFile->token->client_id) {
            return response()->json(['error' => 'Client ID not found'], 400);
        }

        if ($request->client_secret !== $customFile->token->client_secret) {
            return response()->json(['error' => 'Incorrect client secret'], 401);
        }

        if ($request->status !== 'success') {
            return response()->json(['error' => 'Upload unsuccessful'], 400);
        }

        if ($customFile->status == CustomSourceFileEnum::COMPLETE) {
            return response()->json(['error' => 'File already uploaded to this file key.'], 400);
        }

        $customFile->update(['status' => CustomSourceFileEnum::COMPLETE]);

        $fileSize = $this->getFileSize($customFile->key);

        if (! $fileSize) {
            return response()->json(['error' => 'Upload unsuccessful as file size is 0'], 400);
        }

        $importGroup = ImportGroup::create([
            'service_id'      => $customFile->service->id,
            'user_id'         => $customFile->service->user_id,
            'team_id'         => $customFile->service->user?->currentTeam?->id,
            'job_id'          => null,
            'service_type'    => CustomSource::class,
            'status'          => ImportGroupStatus::INITIATED,
            'number_of_files' => 1,
            'started'         => now(),
        ]);

        $user = User::find($customFile->service->user_id);

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $service = Service::find($customFile->service->id);

        if (! $service) {
            return response()->json(['error' => 'Service not found'], 404);
        }

        $team = Team::find($customFile->service->team_id);

        if (! $team) {
            return response()->json(['error' => 'Team not found'], 404);
        }

        $extension = pathinfo($customFile->original_filename, PATHINFO_EXTENSION);

        $file = File::create([
            'name'                   => pathinfo($customFile->original_filename, PATHINFO_FILENAME),
            'extension'              => $extension,
            'slug'                   => Str::slug(pathinfo($customFile->original_filename, PATHINFO_FILENAME)),
            'remote_service_file_id' => uuid(),
            'service_id'             => $customFile->service->id,
            'service_name'           => $customFile->service->name,
            'user_id'                => $user->id,
            'download_url'           => $request->file_key,
            'mime_type'              => $this->getFileTypeFromExtension($extension) . DIRECTORY_SEPARATOR . $extension,
            'size'                   => $fileSize,
            'import_group'           => $importGroup->id,
            'team_id'                => $customFile->service->team_id,
            'type'                   => $this->getFileTypeFromExtension($extension),
        ]);

        $file->markSuccess(
            FileOperationName::DOWNLOAD,
            'File uploaded via Custom Source',
        );

        if ($request->meta && filled($request->meta)) {
            $file->saveMeta(MetaType::extra->value, redact_sensitive_info_from_payload($request->meta));
        }

        [$md5, $sha256] = $file->generateHashes();
        $file->update(compact('md5', 'sha256'));

        $importGroup->update([
            'status'  => ImportGroupStatus::COMPLETED,
            'updated' => now(),
        ]);

        return response()->json([
            'message' => 'File successfully uploaded',
        ]);
    }

    public function getFileSize($key): ?int
    {
        $exists = $this->s3Client->doesObjectExist(config('filesystems.disks.s3.bucket'), $key);

        if (! $exists) {
            return null;
        }

        $object = $this->s3Client->headObject([
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key'    => $key,
        ]);

        return $object['ContentLength'];
    }
}
