<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The MySQL-only FIXED_DEPOSIT migration (2026_09_12_000001) left SQLite
     * behind: enum columns become CHECK constraints there. SQLite cannot alter
     * a CHECK constraint in place, so rebuild the table — MySQL/MariaDB skip.
     */
    public function up(): void
    {
        $this->recoverPartialRename();

        if (!$this->isSqlite()) {
            // MySQL already has FIXED_DEPOSIT from 2026_09_12_000001.
            return;
        }

        $types = ['CURRENT', 'SAVINGS', 'FIXED_DEPOSIT'];
        $this->rebuild($types);
    }

    public function down(): void
    {
        if (!$this->isSqlite()) {
            return;
        }

        DB::table('bank_accounts')->where('account_type', 'FIXED_DEPOSIT')->update(['account_type' => 'SAVINGS']);
        $this->rebuild(['CURRENT', 'SAVINGS']);
    }

    protected function recoverPartialRename(): void
    {
        if (Schema::hasTable('bank_accounts_old') && !Schema::hasTable('bank_accounts')) {
            DB::statement('ALTER TABLE bank_accounts_old RENAME TO bank_accounts');
        }

        if (Schema::hasTable('bank_accounts_old') && Schema::hasTable('bank_accounts')) {
            Schema::dropIfExists('bank_accounts');
            DB::statement('ALTER TABLE bank_accounts_old RENAME TO bank_accounts');
        }
    }

    /**
     * @param  list<string>  $types
     */
    protected function rebuild(array $types): void
    {
        DB::statement('ALTER TABLE bank_accounts RENAME TO bank_accounts_old');
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

    protected function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }
};
