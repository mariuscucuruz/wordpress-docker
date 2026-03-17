<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * All rekognition tables that need file_id index.
     * Original migrations had ->index() but indexes were not created.
     */
    private array $tables = [
        // Old AI tables (HasOne relationships)
        'texts',
        'transcribes',
        // New AI tables (HasMany relationships)
        'celebrity_detections',
        'segment_detections',
        'custom_detections',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            $indexName = "{$tableName}_file_id_index";

            if (Schema::hasIndex($tableName, $indexName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->index('file_id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            $indexName = "{$tableName}_file_id_index";

            if (! Schema::hasIndex($tableName, $indexName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['file_id']);
            });
        }
    }
};
