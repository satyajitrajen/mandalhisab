<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->index();
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('ifsc');
            $table->enum('account_type', ['CURRENT', 'SAVINGS']);
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('upi_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
