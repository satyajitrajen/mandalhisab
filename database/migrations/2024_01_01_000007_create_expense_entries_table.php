<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_entries', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->index();
            $table->string('title');
            $table->enum('category', ['STAGE_MANDAP', 'SOUND_LIGHTING', 'MURTI_DECORATION', 'POOJA_PRASAD', 'SECURITY_LOGISTICS', 'MISCELLANEOUS']);
            $table->decimal('amount', 15, 2);
            $table->enum('payment_mode', ['CASH', 'UPI', 'CHEQUE', 'NET_BANKING']);
            $table->string('paid_to');
            $table->date('date');
            $table->enum('status', ['PAID', 'PENDING']);
            $table->string('bill_url')->nullable();
            $table->string('bill_pending_reason')->nullable();
            $table->string('notes', 250)->nullable();
            $table->string('created_by_user_id', 32)->index();
            $table->string('client_uuid')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_entries');
    }
};
