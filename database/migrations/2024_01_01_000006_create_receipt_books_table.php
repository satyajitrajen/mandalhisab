<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_books', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->index();
            $table->string('book_number');
            $table->integer('start_number');
            $table->integer('end_number');
            $table->string('assigned_to_user_id', 32)->nullable()->index();
            $table->date('assigned_date')->nullable();
            $table->enum('status', ['ACTIVE', 'COMPLETED', 'LOST', 'CANCELLED']);
            $table->integer('used_count')->default(0);
            $table->integer('cancelled_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_books');
    }
};
