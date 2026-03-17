<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rekognition_tasks', function (Blueprint $table) {
            $table->softDeletes()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('rekognition_tasks', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
