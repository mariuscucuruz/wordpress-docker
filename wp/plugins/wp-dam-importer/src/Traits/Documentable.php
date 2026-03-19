<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use MariusCucuruz\DAMImporter\Models\Document;
use Illuminate\Http\UploadedFile;
use Clickonmedia\Manager\Services\StorageService;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Documentable
{
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function saveDocument(UploadedFile $file): Document
    {
        $title = $file->getClientOriginalName();
        $url = $file->store('documents', 's3');
        $size = StorageService::size($url);
        $mime_type = StorageService::mimeType($url);

        return $this->documents()->create(compact('title', 'url', 'size', 'mime_type'));
    }

    public function deleteDocument(Document $document): bool
    {
        if (StorageService::exists($document->url)) {
            StorageService::delete($document->url);
        }

        return $document->delete();
    }
}
