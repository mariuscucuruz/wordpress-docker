<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use MariusCucuruz\DAMImporter\Integrations\CustomSource\Enums\CustomSourceFileEnum;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('custom_source_files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('token_id')->index();
            $table->foreignUuid('service_id')->index();

            $table->string('status')->default(CustomSourceFileEnum::PENDING->value)->nullable();
            $table->string('presigned');

            $table->string('bucket');
            $table->string('key')->index();
            $table->string('original_filename');

            $table->string('path')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_source_files');
    }
};
