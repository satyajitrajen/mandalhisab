<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_handovers', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->index();
            $table->string('from_user_id', 32)->index();
            $table->string('to_user_id', 32)->index();
            $table->decimal('amount', 15, 2);
            $table->json('linked_entry_ids');
            $table->integer('linked_entries_count');
            $table->string('linked_date_range')->nullable();
            $table->string('notes')->nullable();
            $table->string('photo_url')->nullable();
            $table->enum('status', ['PENDING_APPROVAL', 'VERIFIED_ACCEPTED', 'REJECTED']);
            $table->enum('auth_method', ['BIOMETRIC', 'PIN'])->nullable();
            $table->string('verification_notes')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_handovers');
    }
};
