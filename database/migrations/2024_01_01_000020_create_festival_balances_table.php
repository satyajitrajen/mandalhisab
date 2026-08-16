<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('festival_balances', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('festival_id')->unique();
            $table->decimal('cash_treasurer', 12, 2)->default(0);
            $table->decimal('cash_collectors', 12, 2)->default(0);
            $table->decimal('bank', 12, 2)->default(0);
            $table->decimal('upi', 12, 2)->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->foreign('festival_id')
                ->references('id')
                ->on('festivals')
                ->onDelete('cascade');
        });

        // Seed zero balances for any existing festivals so the ledger is never missing.
        $existing = \Illuminate\Support\Facades\DB::table('festivals')->pluck('id');
        foreach ($existing as $festivalId) {
            \Illuminate\Support\Facades\DB::table('festival_balances')->insert([
                'id' => 'bal_' . \Illuminate\Support\Str::random(12),
                'festival_id' => $festivalId,
                'cash_treasurer' => 0,
                'cash_collectors' => 0,
                'bank' => 0,
                'upi' => 0,
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('festival_balances');
    }
};
