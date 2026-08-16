<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streamed_events', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->index();
            $table->string('channel');
            $table->string('event_type');
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streamed_events');
    }
};
