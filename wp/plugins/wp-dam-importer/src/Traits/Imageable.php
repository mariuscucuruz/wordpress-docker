<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use Illuminate\Http\UploadedFile;
use Clickonmedia\Manager\Services\StorageService;
use Illuminate\Database\Eloquent\Casts\Attribute;

// NOTE: If you are using this trait you MUST have a column named `image` in your table
trait Imageable
{
    public function saveImage(UploadedFile $photo, $storagePath = 'images')
    {
        tap($this->image, function ($previous) use ($photo, $storagePath) {
            $this->forceFill([
                'image' => $photo->store(
                    $storagePath, ['disk' => config('filesystems.default')]
                ),
            ])->save();

            if ($previous) {
                StorageService::disk(config('filesystems.default'))->delete($previous);
            }
        });
    }

    public function deleteImage()
    {
        StorageService::disk(config('filesystems.default'))->delete($this->image);

        $this->forceFill(['image' => null])->save();
    }

    public function imageUrl(): Attribute
    {
        $type = __FUNCTION__;

        return Attribute::make(
            get: fn (mixed $value, $attributes) => $this->image
                ? presigned_url($this->image)
                : null
        );
    }
    // TODO: enforce having a column image
}
