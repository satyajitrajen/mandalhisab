<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mandals', function (Blueprint $table) {
            $table->date('registration_paid_through')->nullable()->after('registration_paid_at');
        });

        Schema::table('registration_payments', function (Blueprint $table) {
            $table->string('gateway', 16)->default('razorpay')->index()->after('user_id');
            $table->string('apple_transaction_id', 64)->nullable()->unique()->after('razorpay_payment_id');
            $table->string('apple_original_transaction_id', 64)->nullable()->index()->after('apple_transaction_id');
            $table->string('razorpay_order_id')->nullable()->change();
            $table->date('period_start')->nullable()->after('paid_at');
            $table->date('period_end')->nullable()->after('period_start');
        });

        DB::table('registration_payments')->whereNull('gateway')->update(['gateway' => 'razorpay']);

        // The one-time fee becomes yearly: each paid mandal gets one year from its latest payment.
        DB::table('mandals')
            ->whereNotNull('registration_paid_at')
            ->orderBy('id')
            ->each(function ($mandal) {
                $latestPaidAt = DB::table('registration_payments')
                    ->where('mandal_id', $mandal->id)
                    ->where('status', 'PAID')
                    ->whereNotNull('paid_at')
                    ->max('paid_at');

                $from = Carbon::parse($latestPaidAt ?? $mandal->registration_paid_at);

                DB::table('mandals')
                    ->where('id', $mandal->id)
                    ->update(['registration_paid_through' => $from->copy()->addYear()->toDateString()]);
            });
    }

    public function down(): void
    {
        Schema::table('registration_payments', function (Blueprint $table) {
            $table->dropUnique(['apple_transaction_id']);
            $table->dropIndex(['apple_original_transaction_id']);
            $table->dropIndex(['gateway']);
            $table->dropColumn(['gateway', 'apple_transaction_id', 'apple_original_transaction_id', 'period_start', 'period_end']);
        });

        Schema::table('mandals', function (Blueprint $table) {
            $table->dropColumn('registration_paid_through');
        });
    }
};
