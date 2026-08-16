<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mandals', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('name');
            $table->string('registration_number')->nullable();
            $table->integer('established_year')->nullable();
            $table->string('address');
            $table->string('city');
            $table->string('pincode', 6);
            $table->string('ward_number')->nullable();
            $table->string('contact_number', 10);
            $table->string('logo_url')->nullable();
            $table->string('upi_id')->nullable();
            $table->string('created_by_user_id', 32)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mandals');
    }
};
