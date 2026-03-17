<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MariusCucuruz\DAMImporter\Integrations\Uploader\Controllers\FileUploadController;
use MariusCucuruz\DAMImporter\Integrations\Uploader\Controllers\UppyS3MultipartController;

Route::middleware(['web', 'auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::post('s3presigned_complete_callback', [FileUploadController::class, 's3PresignedCompleteCallback'])->name('s3presigned_complete_callback');

    // Uppy api paths are predetermined by Uppy - api/s3/multipart
    Route::post('/api/s3/multipart', [UppyS3MultipartController::class, 'createMultipartUpload']);
    Route::get('/api/s3/multipart/{uploadId}', [UppyS3MultipartController::class, 'getMultipartUpload']);
    Route::get('/api/s3/multipart/{uploadId}/batch', [UppyS3MultipartController::class, 'prepareUploadParts']);
    Route::post('/api/s3/multipart/{uploadId}/complete', [UppyS3MultipartController::class, 'completeMultipartUpload']);
    Route::delete('/api/s3/multipart/{uploadId}', [UppyS3MultipartController::class, 'abortMultipartUpload']);
    Route::get('/api/s3/multipart/{uploadId}/{partNumber}', [UppyS3MultipartController::class, 'signPart']);
});
