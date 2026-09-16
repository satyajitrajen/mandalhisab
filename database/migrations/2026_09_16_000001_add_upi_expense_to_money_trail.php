<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * money_trail_entries.type was created without UPI_EXPENSE, so any paid
     * UPI expense violated the CHECK constraint (500). SQLite cannot alter a
     * CHECK constraint in place, so rebuild the table preserving all rows.
     */
    public function up(): void
    {
        $types = ['CASH_RECEIVED', 'UPI_RECEIVED', 'BANK_DEPOSIT', 'BANK_WITHDRAWAL', 'CASH_EXPENSE', 'UPI_EXPENSE', 'CASH_HANDOVER', 'FUND_TRANSFER', 'OTHER_INCOME'];

        if (Schema::hasTable('money_trail_entries_old')) {
            // Recover from a partially failed run: drop the half-built table
            // and restore the renamed original before redoing the rebuild.
            Schema::dropIfExists('money_trail_entries');
            DB::statement('ALTER TABLE money_trail_entries_old RENAME TO money_trail_entries');
        }

        DB::statement('ALTER TABLE money_trail_entries RENAME TO money_trail_entries_old');
        // SQLite keeps index names across table renames; free the name first.
        DB::statement('DROP INDEX IF EXISTS money_trail_entries_festival_id_index');

        Schema::create('money_trail_entries', function ($table) use ($types) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->index();
            $table->enum('type', $types);
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->decimal('amount', 15, 2);
            $table->boolean('is_positive');
            $table->string('reference_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->timestamps();
        });

        DB::statement('INSERT INTO money_trail_entries (id, festival_id, type, title, subtitle, amount, is_positive, reference_id, reference_type, created_at, updated_at)
            SELECT id, festival_id, type, title, subtitle, amount, is_positive, reference_id, reference_type, created_at, updated_at FROM money_trail_entries_old');

        Schema::drop('money_trail_entries_old');
    }

    public function down(): void
    {
        $hasUpiExpense = DB::table('money_trail_entries')->where('type', 'UPI_EXPENSE')->exists();
        if ($hasUpiExpense) {
            DB::table('money_trail_entries')->where('type', 'UPI_EXPENSE')->update(['type' => 'CASH_EXPENSE']);
        }

        DB::statement('ALTER TABLE money_trail_entries RENAME TO money_trail_entries_new');
        DB::statement('DROP INDEX IF EXISTS money_trail_entries_festival_id_index');

        Schema::create('money_trail_entries', function ($table) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->index();
            $table->enum('type', ['CASH_RECEIVED', 'UPI_RECEIVED', 'BANK_DEPOSIT', 'BANK_WITHDRAWAL', 'CASH_EXPENSE', 'CASH_HANDOVER', 'FUND_TRANSFER', 'OTHER_INCOME']);
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->decimal('amount', 15, 2);
            $table->boolean('is_positive');
            $table->string('reference_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->timestamps();
        });

        DB::statement('INSERT INTO money_trail_entries (id, festival_id, type, title, subtitle, amount, is_positive, reference_id, reference_type, created_at, updated_at)
            SELECT id, festival_id, type, title, subtitle, amount, is_positive, reference_id, reference_type, created_at, updated_at FROM money_trail_entries_new');

        Schema::drop('money_trail_entries_new');
    }
};
