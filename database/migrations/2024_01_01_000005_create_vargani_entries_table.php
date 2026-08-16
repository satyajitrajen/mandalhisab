<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vargani_entries', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->index();
            $table->string('mandal_id', 32)->index();
            $table->string('receipt_number');
            $table->string('donor_name', 60);
            $table->string('mobile_number', 10)->nullable();
            $table->decimal('amount', 15, 2);
            $table->enum('payment_mode', ['CASH', 'UPI', 'CHEQUE', 'NET_BANKING']);
            $table->string('area');
            $table->string('address')->nullable();
            $table->string('collector_id', 32)->index();
            $table->enum('receipt_type', ['DIGITAL', 'PHYSICAL_BOOK']);
            $table->string('receipt_book_id', 32)->nullable()->index();
            $table->string('notes', 250)->nullable();
            $table->boolean('is_cancelled')->default(false);
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_by_user_id', 32)->nullable()->index();
            $table->string('client_uuid')->nullable();
            $table->string('signature_url')->nullable();
            $table->timestamps();
            $table->unique(['festival_id', 'receipt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vargani_entries');
    }
};
