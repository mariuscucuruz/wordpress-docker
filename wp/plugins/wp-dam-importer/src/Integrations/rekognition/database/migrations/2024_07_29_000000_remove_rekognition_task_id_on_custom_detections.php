<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_detections', function (Blueprint $table) {
            if (Schema::hasColumn('custom_detections', 'rekognition_task_id')) {
                $table->dropIndex(['rekognition_task_id']); // Drop the index first
                $table->dropColumn('rekognition_task_id'); // Then drop the column
            }
        });
    }

    public function down(): void
    {
        Schema::table('custom_detections', function (Blueprint $table) {
            $table->foreignUuid('rekognition_task_id');
        });
    }
};
