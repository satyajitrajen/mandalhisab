<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The MySQL-only FIXED_DEPOSIT migration (2026_09_12_000001) left SQLite
     * behind: enum columns become CHECK constraints there, and the existing
     * one still excludes FIXED_DEPOSIT, so inserts of that type fail. SQLite
     * cannot alter a CHECK constraint in place, so rebuild the table.
     */
    public function up(): void
    {
        $types = ['CURRENT', 'SAVINGS', 'FIXED_DEPOSIT'];

        if (Schema::hasTable('bank_accounts_old')) {
            // Recover from a partially failed run: drop the half-built table
            // and restore the renamed original before redoing the rebuild.
            Schema::dropIfExists('bank_accounts');
            DB::statement('ALTER TABLE bank_accounts_old RENAME TO bank_accounts');
        }

        DB::statement('ALTER TABLE bank_accounts RENAME TO bank_accounts_old');
        // SQLite keeps index names across table renames; free the name first.
        DB::statement('DROP INDEX IF EXISTS bank_accounts_festival_id_index');

        Schema::create('bank_accounts', function ($table) use ($types) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->index();
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('ifsc');
            $table->enum('account_type', $types);
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('upi_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('INSERT INTO bank_accounts (id, festival_id, bank_name, account_number, ifsc, account_type, balance, upi_id, is_active, created_at, updated_at)
            SELECT id, festival_id, bank_name, account_number, ifsc, account_type, balance, upi_id, is_active, created_at, updated_at FROM bank_accounts_old');

        Schema::drop('bank_accounts_old');
    }

    public function down(): void
    {
        DB::table('bank_accounts')->where('account_type', 'FIXED_DEPOSIT')->update(['account_type' => 'SAVINGS']);

        DB::statement('ALTER TABLE bank_accounts RENAME TO bank_accounts_old');
        DB::statement('DROP INDEX IF EXISTS bank_accounts_festival_id_index');

        Schema::create('bank_accounts', function ($table) {
            $table->string('id', 32)->primary();
            $table->string('festival_id', 32)->index();
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('ifsc');
            $table->enum('account_type', ['CURRENT', 'SAVINGS']);
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('upi_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('INSERT INTO bank_accounts (id, festival_id, bank_name, account_number, ifsc, account_type, balance, upi_id, is_active, created_at, updated_at)
            SELECT id, festival_id, bank_name, account_number, ifsc, account_type, balance, upi_id, is_active, created_at, updated_at FROM bank_accounts_old');

        Schema::drop('bank_accounts_old');
    }
};
