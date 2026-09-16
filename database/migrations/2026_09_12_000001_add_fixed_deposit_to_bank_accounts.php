<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// bank_accounts.account_type was created before FIXED_DEPOSIT existed in
// App\Enums\BankAccountType; MySQL rejects inserts with that value.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE bank_accounts MODIFY account_type ENUM('CURRENT', 'SAVINGS', 'FIXED_DEPOSIT') NOT NULL");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE bank_accounts MODIFY account_type ENUM('CURRENT', 'SAVINGS') NOT NULL");
    }
};
