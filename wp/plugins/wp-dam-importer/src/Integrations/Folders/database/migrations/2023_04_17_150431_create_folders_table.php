<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use MariusCucuruz\DAMImporter\Integrations\Folders\Enums\CollectionVisibilityStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->index();
            $table->foreignUuid('team_id')->index();

            $table->string('name')->index();
            $table->string('status')->default(CollectionVisibilityStatus::SHARED->value)->index();
            $table->string('type')->default('default')->index();

            $table->timestamps();
        });

        Schema::table('folders', function (Blueprint $table) {
            $table->foreignUuid('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
