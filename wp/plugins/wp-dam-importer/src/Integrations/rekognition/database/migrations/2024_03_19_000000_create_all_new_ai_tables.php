<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $aiTables = [
        'celebrity_detections',
        'custom_detections',
    ];

    public function up(): void
    {
        foreach ($this->aiTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table) {
                    $table->uuid('id')->primary();

                    $table->foreignUuid('rekognition_task_id')->index();
                    $table->foreignUuid('file_id')->constrained('files')->cascadeOnDelete()->index();
                    $table->string('mime_type')->nullable()->index();

                    $table->string('service_name')->nullable()->index();
                    $table->string('service_type')->nullable()->index();
                    $table->string('model_version')->nullable();

                    $table->string('name')->index();
                    $table->float('confidence')->default(100)->index();
                    $table->unsignedInteger('time')->default(0)->index();
                    $table->unsignedInteger('frame')->default(0);
                    $table->float('offset')->default(0);

                    $table->json('instances')->nullable();
                    $table->json('image_properties')->nullable();
                    $table->json('bounding_box')->nullable();

                    $table->timestamps();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->aiTables as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
