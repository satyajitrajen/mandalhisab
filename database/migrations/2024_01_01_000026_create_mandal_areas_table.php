<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mandal_areas', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('mandal_id', 32)->index();
            $table->string('name');
            $table->string('ward_number')->nullable();
            $table->timestamps();

            $table->foreign('mandal_id')->references('id')->on('mandals')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mandal_areas');
    }
};
