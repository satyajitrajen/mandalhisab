<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_deletion_requests', function (Blueprint $table) {
            $table->string('source', 16)->default('WEB')->after('status');
            $table->timestamp('scheduled_for')->nullable()->after('user_id');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->index(['status', 'source', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::table('account_deletion_requests', function (Blueprint $table) {
            $table->dropIndex(['status', 'source', 'scheduled_for']);
            $table->dropColumn(['source', 'scheduled_for', 'cancelled_at']);
        });
    }
};
