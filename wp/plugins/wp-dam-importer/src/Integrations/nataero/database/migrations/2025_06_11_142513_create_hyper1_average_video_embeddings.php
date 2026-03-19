<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\Hyper1AverageVideoEmbedding;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! config('database.connections.pgsql.vector_enabled')) {
            return;
        }

        Schema::create('hyper1_average_video_embeddings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('nataero_task_id')->index();

            $table->foreignUuid('file_id')
                ->index()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignUuid('service_id')->nullable()->index();

            $table->string('service_name')->nullable();
            $table->foreignUuid('team_id')->index();

            if (DB::getDriverName() === 'pgsql') {
                $table->vector(
                    'embedding',
                    Hyper1AverageVideoEmbedding::VECTOR_DIMENSION
                )->nullable();
            } else {
                $table->json('embedding')->nullable();
            }

            $table->integer('num_key_frames')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'file_id']);
            $table->index(['team_id', 'service_name']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                CREATE INDEX hyper1_average_video_embeddings_hnsw
                ON hyper1_average_video_embeddings
                USING hnsw (embedding vector_cosine_ops)
                WITH (m = 16, ef_construction = 64);
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! config('database.connections.pgsql.vector_enabled')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS hyper1_average_video_embeddings_hnsw;');
        }

        Schema::dropIfExists('hyper1_average_video_embeddings');
    }
};
