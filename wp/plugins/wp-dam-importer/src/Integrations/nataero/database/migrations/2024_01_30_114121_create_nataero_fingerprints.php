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
        Schema::create('nataero_fingerprints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('nataero_task_id')->index();
            $table->foreignUuid('file_id')->nullable()->index();

            $table->string('function_type')->nullable()->index(); // e.g., wave hash, hash brownie, image hash

            $table->string('fingerprint', 1225)->nullable()->index(); //  single frame hash
            $table->string('fingerprint_type')->nullable()->index(); // e.g., single or sample_rate

            $table->string('frame')->nullable();
            $table->string('offset')->nullable();

            $table->string('fps')->nullable();
            $table->string('total_frames')->nullable();
            $table->string('duration_seconds')->nullable();

            $table->string('var_one')->nullable(); // e.g., R, G, B
            $table->string('var_two')->nullable();
            $table->string('var_three')->nullable();
            $table->string('var_four')->nullable();
            $table->string('var_five')->nullable();

            $table->string('timestamp_1')->nullable();
            $table->string('timestamp_2')->nullable();
            $table->string('timestamp_difference')->nullable();

            $table->json('extra')->nullable();

            $table->string('version');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nataero_fingerprints');
    }
};
