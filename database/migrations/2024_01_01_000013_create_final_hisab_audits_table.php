<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_hisab_audits', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->unique();
            $table->decimal('opening_balance', 15, 2);
            $table->decimal('vargani_total', 15, 2);
            $table->decimal('other_income_total', 15, 2);
            $table->decimal('total_income', 15, 2);
            $table->decimal('total_expenses', 15, 2);
            $table->decimal('closing_balance', 15, 2);
            $table->boolean('president_signed')->default(false);
            $table->boolean('treasurer_signed')->default(false);
            $table->dateTime('president_signed_at')->nullable();
            $table->dateTime('treasurer_signed_at')->nullable();
            $table->string('president_user_id', 32)->nullable()->index();
            $table->string('treasurer_user_id', 32)->nullable()->index();
            $table->enum('treasurer_auth_method', ['BIOMETRIC', 'PIN'])->nullable();
            $table->string('pdf_report_url')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_hisab_audits');
    }
};
