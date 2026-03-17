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
        Schema::create('event_analytics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('client_id')->nullable()->index();
            $table->string('user_id')->nullable()->constrained()->index();
            $table->foreignUuid('team_id')->nullable()->constrained()->index()->cascadeOnDelete();
            $table->string('session_id')->nullable()->index();
            $table->string('model_type')->nullable();
            $table->string('model_id')->nullable();
            $table->string('url_path', 1024)->nullable();
            $table->string('event_name', 512)->nullable();
            $table->string('event_value', 1024)->nullable();
            $table->string('event_type', 1024)->nullable();
            $table->string('event_category', 1024)->nullable();
            $table->string('ip_address')->nullable();
            $table->text('agent')->nullable();
            $table->tinyInteger('status')->default('1');
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->index(['event_name', 'event_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_analytics');
    }
};
