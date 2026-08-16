<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mandal_members', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('mandal_id', 32)->index();
            $table->string('user_id', 32)->index();
            $table->enum('role', ['SUPER_ADMIN', 'ADMIN', 'TREASURER', 'COLLECTOR', 'MEMBER']);
            $table->string('area')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->dateTime('joined_at');
            $table->timestamps();
            $table->unique(['mandal_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mandal_members');
    }
};
