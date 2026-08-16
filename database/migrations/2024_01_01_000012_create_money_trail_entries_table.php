<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_trail_entries', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->index();
            $table->enum('type', ['CASH_RECEIVED', 'UPI_RECEIVED', 'BANK_DEPOSIT', 'BANK_WITHDRAWAL', 'CASH_EXPENSE', 'CASH_HANDOVER', 'FUND_TRANSFER', 'OTHER_INCOME']);
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->decimal('amount', 15, 2);
            $table->boolean('is_positive');
            $table->string('reference_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_trail_entries');
    }
};
