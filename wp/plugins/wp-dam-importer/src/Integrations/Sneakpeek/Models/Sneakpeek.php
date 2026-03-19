<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\Sneakpeek\Models;

use Illuminate\Database\Eloquent\Model;
use Database\Factories\SneakpeekFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Sneakpeek extends Model
{
    use HasFactory;

    protected $table = 'sneakpeeks';

    protected static function newFactory()
    {
        return SneakpeekFactory::new();
    }

    public function sneakpeekable()
    {
        return $this->morphTo();
    }

    protected function remotePath(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, $attributes) => presigned_url($value)
        );
    }

    protected function originalRemotePath(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, $attributes) => $this->attributes['remote_path']
        );
    }
}
