<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('album_file', function (Blueprint $table) {
            $table->id();
            $table->foreignUuId('album_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignUuid('file_id')->index()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_file');
    }
};
