<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use Laravel\Jetstream\Features;
use Illuminate\Http\UploadedFile;
use Clickonmedia\Manager\Services\StorageService;
use Illuminate\Database\Eloquent\Casts\Attribute;

trait Photoable
{
    public function updateProfilePhoto(UploadedFile $photo, $storagePath = 'profile-photos')
    {
        tap($this->profile_photo_path, function ($previous) use ($photo, $storagePath) {
            $this->forceFill([
                'profile_photo_path' => $photo->store(
                    $storagePath, ['disk' => $this->profilePhotoDisk()]
                ),
            ])->save();

            if ($previous) {
                StorageService::disk($this->profilePhotoDisk())->delete($previous);
            }

            cache()->forget("temporary_profilePhotoUrl_{$this->id}");
            cache()->forget("temporary_profilePhotoPath_{$this->id}");
        });
    }

    public function deleteProfilePhoto()
    {
        if (! Features::managesProfilePhotos() || empty($this->profile_photo_path)) {
            return;
        }

        StorageService::disk($this->profilePhotoDisk())->delete($this->profile_photo_path);

        $this->forceFill([
            'profile_photo_path' => null,
        ])->save();
    }

    public function profilePhotoUrl(): Attribute
    {
        $type = __FUNCTION__;

        return Attribute::make(
            get: fn (mixed $value, $attributes) => $this->profile_photo_path
                ? presigned_url($this->profile_photo_path)
                : $this->defaultProfilePhotoUrl()
        );
    }

    protected function originalProfilePhotoUrl(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, mixed $attributes) => $this->attributes['profile_photo_path']
        );
    }

    protected function defaultProfilePhotoUrl($type = null)
    {
        $theme = match ($type) {
            'team'  => 'medialake_team',
            default => 'medialake'
        };
        $avatar = resolve('avatar');

        return $avatar->create($this->name)->setTheme($theme)->toBase64();
    }

    protected function profilePhotoDisk()
    {
        return config('jetstream.profile_photo_disk');
    }
}
