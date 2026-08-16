<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'full_name' => 'Demo Admin',
            'username' => 'demoadmin',
            'phone' => '9876543210',
            'email' => 'admin@mandalhisab.in',
            'password' => \Illuminate\Support\Facades\Hash::make('AdminPass@123'),
            'security_pin' => \Illuminate\Support\Facades\Hash::make('1234'),
            'default_language' => 'en',
            'is_biometric_enabled' => true,
        ]);
    }
}
