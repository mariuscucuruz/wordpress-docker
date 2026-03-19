<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exif_metadata', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('file_id')->constrained('files')->cascadeOnDelete();
            $table->string('version')->nullable();
            $table->jsonb('data');
            $table->timestamps();

            $table->unique('file_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX exif_metadata_data_gin_index ON exif_metadata USING gin (data)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exif_metadata');
    }
};
