<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Models;

use MariusCucuruz\DAMImporter\Traits\Photoable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;
    use HasUuids;
    use Photoable;

    protected $hidden = [
        'token',
    ];

    // @phpstan-ignore-next-line
    protected $appends = ['profile_photo_url']; // N+1 issue

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function getRouteKeyName(): string
    {
        return 'username';
    }
}
