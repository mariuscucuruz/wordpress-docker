<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use MariusCucuruz\DAMImporter\Integrations\Nataero\Models\Hyper1VideoEmbedding;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.connections.pgsql.vector_enabled')) {
            Schema::create('hyper1_video_embeddings', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('nataero_task_id')->index();
                $table->foreignUuid('file_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUuid('service_id')->nullable()->index();
                $table->string('service_name')->nullable()->index();
                $table->foreignUuid('team_id')->nullable()->index();

                if (DB::getDriverName() === 'pgsql') {
                    $table->vector('embedding', Hyper1VideoEmbedding::VECTOR_DIMENSION)->nullable();
                } else {
                    $table->json('embedding')->nullable();
                }
                $table->string('key_frame')->nullable();
                $table->timestamps();

                $table->index(['service_id', 'file_id']);
                $table->index(['team_id', 'service_name']);
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement(<<<'SQL'
                    CREATE INDEX hyper1_video_embeddings_hnsw
                    ON hyper1_video_embeddings
                    USING hnsw (embedding vector_cosine_ops)
                    WITH (m = 16, ef_construction = 64);
                 SQL
                );
            }
        }
    }

    public function down(): void
    {
        if (config('database.connections.pgsql.vector_enabled')) {
            Schema::dropIfExists('hyper1_video_embeddings');
            DB::statement('DROP INDEX IF EXISTS hyper1_video_embeddings_hnsw;');
        }
    }
};
