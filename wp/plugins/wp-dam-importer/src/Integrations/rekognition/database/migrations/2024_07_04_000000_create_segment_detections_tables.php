<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segment_detections', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('rekognition_task_id')->index();
            $table->foreignUuid('file_id')->constrained('files')->cascadeOnDelete()->index();
            $table->string('mime_type')->nullable()->index();

            $table->string('service_name')->nullable()->index();
            $table->string('service_type')->nullable()->index();
            $table->string('model_version')->nullable();

            $table->string('type')->index();
            $table->integer('start_time_millis')->unsigned()->default(0);
            $table->integer('end_time_millis')->unsigned()->default(0);
            $table->string('start_time');
            $table->string('end_time');
            $table->string('duration_time');
            $table->float('confidence')->default(100)->index();
            $table->unsignedInteger('start_frame')->default(0);
            $table->unsignedInteger('end_frame')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segment_detections');
    }
};
