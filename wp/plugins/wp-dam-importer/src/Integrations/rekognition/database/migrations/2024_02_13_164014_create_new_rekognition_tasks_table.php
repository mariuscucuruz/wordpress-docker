<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rekognition_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('file_id')->constrained('files')->cascadeOnDelete()->index();
            $table->string('service_name')->nullable()->index();
            $table->string('service_type')->nullable()->index();
            $table->string('job_type')->nullable()->index();
            $table->string('job_id')->nullable()->index();
            $table->string('job_status')->nullable()->index(); // IN_PROGRESS | SUCCEEDED | FAILED | COMPLETED
            $table->boolean('attempts')->default(false);
            $table->boolean('analyzed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rekognition_tasks');
    }
};
