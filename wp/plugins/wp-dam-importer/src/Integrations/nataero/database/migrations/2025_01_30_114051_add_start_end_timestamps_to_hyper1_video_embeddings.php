<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.connections.pgsql.vector_enabled')) {
            Schema::table('hyper1_video_embeddings', function (Blueprint $table) {
                $table->string('timestamp_1')->nullable();
                $table->string('timestamp_2')->nullable();
                $table->string('timestamp_difference')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.connections.pgsql.vector_enabled')) {
            Schema::table('hyper1_video_embeddings', function (Blueprint $table) {
                $table->dropColumn('timestamp_1')->nullable();
                $table->dropColumn('timestamp_2')->nullable();
                $table->dropColumn('timestamp_difference')->nullable();
            });
        }
    }
};
