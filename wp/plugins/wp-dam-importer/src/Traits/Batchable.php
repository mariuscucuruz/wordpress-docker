<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Database\Eloquent\Model;

trait Batchable
{
    public static function findBatch(Model $model): ?Batch
    {
        $batchQuery = DB::table('job_batches')
            ->where('name', self::class . ':' . $model->id)
            ->latest()
            ->first('id');

        if (! $batchQuery) {
            return null;
        }

        return Bus::findBatch($batchQuery->id);
    }
}
