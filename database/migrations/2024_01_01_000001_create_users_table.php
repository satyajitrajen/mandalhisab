<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('full_name');
            $table->string('username')->nullable()->unique();
            $table->string('phone')->nullable()->unique();
            $table->string('email')->nullable();
            $table->string('password');
            $table->string('security_pin')->nullable();
            $table->string('avatar_url')->nullable();
            $table->enum('default_language', ['en', 'mr'])->default('en');
            $table->boolean('is_biometric_enabled')->default(false);
            $table->string('active_festival_id', 32)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
