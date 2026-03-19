<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $aiTables = [
        'texts',
        'transcribes',
    ];

    public function up(): void
    {
        foreach ($this->aiTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table) use ($tableName) {
                    $table->id();
                    $table->foreignUuid('file_id')->constrained('files')->cascadeOnDelete()->index();
                    $table->foreignUuid('rekognition_task_id')->index();
                    $table->json('items')->nullable();

                    if ($tableName === 'transcribes') {
                        $table->string('language_code')->nullable()->index();
                    }

                    $table->timestamps();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->aiTables as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
