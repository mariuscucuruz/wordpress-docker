<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Uploader\Controllers;

use MariusCucuruz\DAMImporter\Models\File;
use Illuminate\Http\Request;
use MariusCucuruz\DAMImporter\Enums\FileOperationName;
use MariusCucuruz\DAMImporter\Http\Controllers\Controller;
use MariusCucuruz\DAMImporter\Integrations\Uploader\Jobs\UploadProcessFile;

class FileUploadController extends Controller
{
    protected function s3PresignedCompleteCallback(Request $request): void
    {
        $attributes = $request->validate([
            'uploadURL'   => 'string',
            'thumbBase64' => 'string|nullable',
            'fileS3key'   => 'required|string',
            'albumId'     => 'nullable|string',
            'name'        => 'required|string',
            'extension'   => 'required|string',
            'type'        => 'required|string',
            'size'        => 'required|integer',
            'duration'    => 'integer',
            'licenseId'   => 'nullable|string|exists:licenses,id',
        ]);

        $file = File::query()
            ->where('download_url', $attributes['fileS3key'])
            ?->latest()
            ?->first();

        $file?->markInitialized(FileOperationName::DOWNLOAD, 'Upload complete, queued for download');

        dispatch(new UploadProcessFile(auth()->user(), $attributes));
    }
}
