<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('festivals', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('mandal_id', 32)->index();
            $table->string('name');
            $table->string('year');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['ACTIVE', 'UPCOMING', 'COMPLETED']);
            $table->decimal('budget_goal', 15, 2)->default(0);
            $table->string('description', 500)->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('festivals');
    }
};
