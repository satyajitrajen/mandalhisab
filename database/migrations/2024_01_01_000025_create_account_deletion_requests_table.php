<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('phone_or_username');
            $table->string('mandal_name')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('PENDING');
            $table->string('user_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('phone_or_username');
            $table->index('status');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_requests');
    }
};
