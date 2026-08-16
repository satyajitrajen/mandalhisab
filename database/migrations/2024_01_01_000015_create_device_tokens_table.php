<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('user_id', 32)->index();
            $table->string('token');
            $table->enum('platform', ['android', 'ios', 'web', 'windows']);
            $table->dateTime('last_seen_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
