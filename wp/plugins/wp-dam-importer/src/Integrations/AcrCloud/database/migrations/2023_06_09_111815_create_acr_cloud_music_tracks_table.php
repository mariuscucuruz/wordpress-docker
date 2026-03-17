<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('acr_cloud_music_tracks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('file_id')->nullable()->index()->constrained()->cascadeOnDelete();

            $table->string('title')->nullable();
            $table->string('artists')->nullable();
            $table->string('album')->nullable();
            $table->string('label')->nullable();
            $table->integer('start_time')->nullable()->unsigned();
            $table->integer('duration')->nullable()->unsigned();
            $table->string('isrc')->nullable()->index();
            $table->integer('score')->nullable()->unsigned();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acr_cloud_music_tracks');
    }
};
