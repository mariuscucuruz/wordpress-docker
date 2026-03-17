<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nataero_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('file_id')->index();
            $table->string('function_type')->index();
            $table->string('status')->nullable()->index();
            $table->uuid('state')->nullable()->index();
            $table->string('remote_nataero_task_id')->nullable()->index();

            $table->string('version');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nataero_tasks');
    }
};
