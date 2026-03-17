<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Uploader\Controllers;

use Throwable;
use MariusCucuruz\DAMImporter\Models\File;
use MariusCucuruz\DAMImporter\Support\Path;
use Illuminate\Http\Request;
use Aws\Exception\AwsException;
use Illuminate\Http\JsonResponse;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use TMariusCucuruz\DAMImporter\LaravelUppyS3MultipartUpload\Http\Controllers\UppyS3MultipartController as UppyBaseController;

/**
 * Useful github blob: https://github.com/transloadit/uppy/blob/main/examples/aws-nodejs/index.js
 */
class UppyS3MultipartController extends UppyBaseController
{
    public function createMultipartUpload(Request $request): JsonResponse
    {
        $this->authorize('create', File::class);

        $this->validate($request, [
            'filename'      => 'required|string|max:255',
            'metadata.name' => 'required|string|max:255',
            'type'          => 'required|string|max:255',
        ]);

        $type = $request->input('type');
        $filenameRequest = $request->input('filename');

        $baseDirectory = Path::join(
            config('manager.directory.uploads'),
            str(microtime())->slug('_')->toString()
        ) . DIRECTORY_SEPARATOR;

        $fileExtension = pathinfo($filenameRequest, PATHINFO_EXTENSION);
        $fileNameLength = strlen($baseDirectory) - strlen(".{$fileExtension}");

        $fileName = str(pathinfo($filenameRequest, PATHINFO_FILENAME))
            ->slug()
            ->limit($fileNameLength, '')
            ->toString();

        $key = $baseDirectory . "{$fileName}.{$fileExtension}";

        try {
            $result = $this->client->createMultipartUpload([
                'Bucket'             => $this->bucket,
                'Key'                => $key,
                'ContentType'        => $type,
                'ContentDisposition' => 'inline',
            ]);
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 500);
        }

        return response()
            ->json(['uploadId' => $result['UploadId'], 'key' => $result['Key']]);
    }

    public function prepareUploadParts(Request $request, string|int $uploadId): JsonResponse
    {
        $key = $this->encodeURIComponent($request->input('key'));
        $partNumbers = explode(',', $request->input('partNumbers'));
        $presignedUrls = [];

        foreach ($partNumbers as $partNumber) {
            $command = $this->client->getCommand('uploadPart', [
                'Bucket'     => $this->bucket,
                'Key'        => $key,
                'UploadId'   => $uploadId,
                'PartNumber' => (int) $partNumber,
                'Body'       => '',
            ]);

            $presignedUrls[$partNumber] = (string) $this->client->createPresignedRequest(
                $command,
                config('uploader.presigned_url.expiry_time')
            )->getUri();
        }

        return response()->json(compact('presignedUrls'));
    }

    public function getMultipartUpload(Request $request, string|int $uploadId): JsonResponse
    {
        $key = $request->query('key');

        try {
            $result = $this->client->listParts([
                'Bucket'   => config('filesystems.disks.s3.bucket'),
                'Key'      => $key,
                'UploadId' => $uploadId,
            ]);

            $parts = $result['Parts'] ?? [];

            return response()->json($parts);
        } catch (AwsException $e) {
            return response()->json(['error' => 'Failed to list parts', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function signPart(string|int $uploadId, string|int $partNumber): JsonResponse
    {
        $key = $this->encodeURIComponent(request()->get('key'));
        $command = $this->client->getCommand('uploadPart', [
            'UploadId'   => $uploadId,
            'Key'        => $key,
            'Bucket'     => $this->bucket,
            'PartNumber' => (int) $partNumber,
            'Body'       => '',
        ]);

        $preSignedUrl = (string) $this->client->createPresignedRequest(
            $command,
            config('uploader.presigned_url.expiry_time')
        )->getUri();

        return response()->json([
            'url' => $preSignedUrl,
        ]);
    }

    public function completeMultipartUpload(Request $request, string|int $uploadId): JsonResponse
    {
        $key = $this->encodeURIComponent($request->input('key'));
        $parts = $request->input('parts');

        $result = $this->client->completeMultipartUpload([
            'Bucket'          => $this->bucket,
            'Key'             => $key,
            'UploadId'        => $this->encodeURIComponent($uploadId),
            'MultipartUpload' => ['Parts' => $parts],
        ]);

        $location = $result['Location'];

        return response()
            ->json(compact('location'));
    }

    public function abortMultipartUpload(Request $request, string|int $uploadId): JsonResponse
    {
        $key = $request->input('key');

        $this->client->abortMultipartUpload([
            'Bucket'   => $this->bucket,
            'Key'      => $this->encodeURIComponent($key),
            'UploadId' => $this->encodeURIComponent($uploadId),
        ]);

        return response()->json();
    }
}
