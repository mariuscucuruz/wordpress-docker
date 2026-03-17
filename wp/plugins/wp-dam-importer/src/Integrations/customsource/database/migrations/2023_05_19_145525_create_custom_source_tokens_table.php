<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use MariusCucuruz\DAMImporter\Integrations\CustomSource\Enums\CustomSourceTokenEnum;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('custom_source_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('service_id')->index();
            $table->foreignUuid('user_id')->index();

            $table->string('client_id', 3102);
            $table->string('client_secret', 3102);

            $table->string('status')->default(CustomSourceTokenEnum::INACTIVE->value)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_source_tokens');
    }
};
