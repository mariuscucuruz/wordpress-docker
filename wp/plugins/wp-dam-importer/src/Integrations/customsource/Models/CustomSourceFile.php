<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Integrations\CustomSource\Models;

use MariusCucuruz\DAMImporter\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use MariusCucuruz\DAMImporter\Integrations\CustomSource\Enums\CustomSourceFileEnum;

class CustomSourceFile extends Model
{
    use HasFactory;

    public function token()
    {
        return $this->belongsTo(CustomSourceToken::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    protected function casts(): array
    {
        return [
            'status' => CustomSourceFileEnum::class,
        ];
    }
}
