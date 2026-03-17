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
        Schema::create('albums', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->index();
            $table->foreignUuid('folder_id')->nullable()->index();
            $table->foreignUuid('user_id')->index();

            $table->string('name')->index();
            $table->string('status')->default(CollectionVisibilityStatus::SHARED->value)->index();
            $table->string('type')->default('default')->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('albums');
    }
};
