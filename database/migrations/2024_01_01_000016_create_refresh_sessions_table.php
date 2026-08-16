<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_sessions', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('user_id', 32)->index();
            $table->string('refresh_token_hash');
            $table->string('device_token')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_sessions');
    }
};
