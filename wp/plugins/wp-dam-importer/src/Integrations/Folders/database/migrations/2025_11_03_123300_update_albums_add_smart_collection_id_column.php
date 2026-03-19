<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->foreignUuid('smart_collection_id')
                ->nullable()
                ->unique()
                ->constrained('smart_collections')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropForeign(['smart_collection_id']);
            $table->dropColumn('smart_collection_id');
        });
    }
};
