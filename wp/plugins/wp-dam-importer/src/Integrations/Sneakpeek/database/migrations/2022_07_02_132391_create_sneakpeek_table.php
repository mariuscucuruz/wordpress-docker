<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sneakpeeks', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('sneakpeekable');

            $table->string('version')->nullable();
            $table->string('tmp_path', 1024)->nullable();
            $table->string('remote_path', 1024)->nullable();
            $table->string('object_url', 1024)->nullable();
            $table->string('frame')->nullable();
            $table->string('timestamp')->nullable();
            $table->string('start_time')->nullable();
            $table->string('end_time')->nullable();
            $table->string('type')->nullable()->index();
            $table->float('width')->nullable()->unsigned();
            $table->float('height')->nullable()->unsigned();
            $table->string('fps')->nullable();

            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down()
    {
        Schema::dropIfExists('sneakpeeks');
    }
};
