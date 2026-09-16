<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mandals', function (Blueprint $table) {
            $table->timestamp('registration_paid_at')->nullable()->after('created_by_user_id');
        });

        // Existing mandals were created before the ₹101 fee existed.
        DB::table('mandals')
            ->whereNull('registration_paid_at')
            ->update(['registration_paid_at' => DB::raw('created_at')]);

        Schema::create('registration_payments', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('mandal_id', 32)->index();
            $table->string('user_id', 32)->index();
            $table->string('razorpay_order_id')->unique();
            $table->string('razorpay_payment_id')->nullable()->index();
            $table->unsignedInteger('amount_paise');
            $table->string('currency', 8)->default('INR');
            $table->string('status', 24);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_payments');

        Schema::table('mandals', function (Blueprint $table) {
            $table->dropColumn('registration_paid_at');
        });
    }
};
