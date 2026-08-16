<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_tokens(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'fullName' => 'Test User',
            'usernameOrPhone' => 'testuser123',
            'password' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['accessToken', 'refreshToken', 'user'],
            ])
            ->assertJsonPath('data.user.name', 'Test User');
    }

    public function test_register_fails_when_fields_missing(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertStatus(422);
    }

    public function test_password_must_be_at_least_8_chars(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'fullName' => 'Test User',
            'usernameOrPhone' => 'testuser123',
            'password' => 'short',
        ])->assertStatus(422);
    }

    public function test_login_successful(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'fullName' => 'Test User',
            'usernameOrPhone' => 'testuser123',
            'password' => 'password123',
        ])->assertStatus(201);

        $this->postJson('/api/v1/auth/login', [
            'usernameOrPhone' => 'testuser123',
            'password' => 'password123',
        ])->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['accessToken', 'refreshToken']]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'fullName' => 'Test User',
            'usernameOrPhone' => 'testuser123',
            'password' => 'password123',
        ])->assertStatus(201);

        $this->postJson('/api/v1/auth/login', [
            'usernameOrPhone' => 'testuser123',
            'password' => 'wrongpassword',
        ])->assertStatus(401);
    }

    public function test_deleted_user_cannot_login(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'fullName' => 'Test User',
            'usernameOrPhone' => 'testuser123',
            'password' => 'password123',
        ])->assertStatus(201);

        $user = \App\Models\User::where('username', 'testuser123')->first();
        $user->delete(); // soft-delete

        $this->postJson('/api/v1/auth/login', [
            'usernameOrPhone' => 'testuser123',
            'password' => 'password123',
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_delete_me_requires_password(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'fullName' => 'Test User',
            'usernameOrPhone' => 'testuser123',
            'password' => 'password123',
        ])->assertStatus(201);

        $token = $register->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/auth/me', ['password' => 'wrongpassword'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_delete_me_anonymizes_and_soft_deletes_user(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'fullName' => 'Test User',
            'usernameOrPhone' => 'testuser123',
            'password' => 'password123',
        ])->assertStatus(201);

        $token = $register->json('data.accessToken');
        $userId = $register->json('data.user.id');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/auth/me', ['password' => 'password123'])
            ->assertStatus(200)
            ->assertJsonPath('message', 'Account deleted permanently');

        $user = \App\Models\User::withTrashed()->find($userId);
        $this->assertNotNull($user->deleted_at);
        $this->assertEquals('Deleted User', $user->full_name);
        $this->assertNull($user->username);
        $this->assertNull($user->phone);
        $this->assertNull($user->email);
    }

    public function test_public_deletion_request_creates_pending_record(): void
    {
        $this->postJson('/api/v1/public/account-deletion-request', [
            'phoneOrUsername' => '9876543210',
            'mandalName' => 'Test Mandal',
            'reason' => 'No longer needed',
        ])->assertStatus(200)
            ->assertJsonPath('message', 'Request submitted');

        $record = \App\Models\AccountDeletionRequest::where('phone_or_username', '9876543210')->first();
        $this->assertNotNull($record);
        $this->assertEquals('PENDING', $record->status);
        $this->assertEquals('Test Mandal', $record->mandal_name);
        $this->assertEquals('No longer needed', $record->reason);
    }
}
