<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_sequences', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('festival_id');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique('festival_id');
            $table->foreign('festival_id')
                ->references('id')
                ->on('festivals')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_sequences');
    }
};
