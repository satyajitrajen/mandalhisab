<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 1 cleanup: remove all financial records so the new
        // receipt_sequence + festival_balance ledger starts from a clean state.
        DB::statement('DELETE FROM money_trail_entries');
        DB::statement('DELETE FROM fund_transfers');
        DB::statement('DELETE FROM cash_handovers');
        DB::statement('DELETE FROM other_income');
        DB::statement('DELETE FROM expense_entries');
        DB::statement('DELETE FROM vargani_entries');
        DB::statement('DELETE FROM receipt_books');
        DB::statement('DELETE FROM bank_accounts');
        DB::statement('DELETE FROM final_hisab_audits');
        DB::statement('DELETE FROM notifications');

        // Reset ledger and sequences
        DB::table('festival_balances')->update([
            'cash_treasurer' => 0,
            'cash_collectors' => 0,
            'bank' => 0,
            'upi' => 0,
            'version' => 0,
        ]);

        DB::table('receipt_sequences')->update(['next_number' => 1]);
    }

    public function down(): void
    {
        // Irreversible data wipe — no rollback possible.
    }
};
