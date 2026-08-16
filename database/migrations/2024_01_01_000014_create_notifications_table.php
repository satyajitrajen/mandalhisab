<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('user_id', 32)->index();
            $table->string('mandal_id', 32)->nullable()->index();
            $table->string('festival_id', 32)->nullable()->index();
            $table->string('title');
            $table->string('body');
            $table->enum('type', ['HANDOVER_INITIATED', 'HANDOVER_APPROVED', 'HANDOVER_REJECTED', 'VARGANI_CREATED', 'EXPENSE_CREATED', 'FINAL_HISAB_SIGNED', 'GENERAL']);
            $table->string('reference_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
