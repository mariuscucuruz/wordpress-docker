<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_analytics', function (Blueprint $table) {
            $table->uuid('team_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('event_analytics', function (Blueprint $table) {
            $table->uuid('team_id')->nullable(false)->change();
        });
    }
};
