<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('idempotency_key');
            $table->string('user_id', 32)->index();
            $table->string('route');
            $table->string('request_hash');
            $table->text('response_body');
            $table->dateTime('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
