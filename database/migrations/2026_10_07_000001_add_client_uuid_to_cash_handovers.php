<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency key for handover submissions: a retried or double-tapped
     * submit resends the same clientUuid and must not create a second row.
     */
    public function up(): void
    {
        Schema::table('cash_handovers', function (Blueprint $table) {
            $table->string('client_uuid', 64)->nullable()->after('festival_id');
            $table->unique(['festival_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_handovers', function (Blueprint $table) {
            $table->dropUnique(['festival_id', 'client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
