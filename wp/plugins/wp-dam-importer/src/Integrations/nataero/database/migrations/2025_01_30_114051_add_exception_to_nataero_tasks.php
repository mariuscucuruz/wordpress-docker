<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nataero_tasks', function (Blueprint $table) {
            $table->text('exception')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nataero_tasks', function (Blueprint $table) {
            $table->dropColumn('exception');
        });
    }
};
