<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_income', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->index();
            $table->string('title');
            $table->decimal('amount', 15, 2);
            $table->date('date');
            $table->string('notes')->nullable();
            $table->string('created_by_user_id', 32)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_income');
    }
};
