<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mandals', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('upi_id');
        });
    }

    public function down(): void
    {
        Schema::table('mandals', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }
};
