<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_transfers', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->index();
            $table->enum('from_bucket', ['CASH_TREASURER', 'CASH_COLLECTORS', 'BANK', 'UPI']);
            $table->enum('to_bucket', ['CASH_TREASURER', 'CASH_COLLECTORS', 'BANK', 'UPI']);
            $table->string('bank_account_id', 32)->nullable()->index();
            $table->decimal('amount', 15, 2);
            $table->string('notes')->nullable();
            $table->string('created_by_user_id', 32)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_transfers');
    }
};
