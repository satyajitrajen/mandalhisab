<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'fullName' => 'Test User',
            'usernameOrPhone' => '9876543210',
            'email' => 'test.user@example.com',
            'password' => 'password123',
        ], $overrides);
    }

    public function test_register_returns_tokens(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->registerPayload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['accessToken', 'refreshToken', 'user'],
            ])
            ->assertJsonPath('data.user.name', 'Test User')
            ->assertJsonPath('data.user.email', 'test.user@example.com');

        $this->assertDatabaseHas('users', [
            'phone' => '9876543210',
            'email' => 'test.user@example.com',
        ]);
    }

    public function test_register_rejects_invalid_phone(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'usernameOrPhone' => '12345',
        ]))->assertStatus(422);

        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'usernameOrPhone' => '5123456789',
        ]))->assertStatus(422);
    }

    public function test_register_rejects_duplicate_phone_and_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())
            ->assertStatus(201);

        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'usernameOrPhone' => '9876543210',
            'email' => 'other@example.com',
        ]))->assertStatus(422);

        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'usernameOrPhone' => '9876500001',
            'email' => 'test.user@example.com',
        ]))->assertStatus(422);
    }

    public function test_register_requires_email(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'fullName' => 'Test User',
            'usernameOrPhone' => '9876543210',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_register_fails_when_fields_missing(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertStatus(422);
    }

    public function test_password_must_be_at_least_8_chars(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload([
            'password' => 'short',
        ]))->assertStatus(422);
    }

    public function test_login_successful(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        $this->postJson('/api/v1/auth/login', [
            'usernameOrPhone' => '9876543210',
            'password' => 'password123',
        ])->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['accessToken', 'refreshToken']]);

        $this->postJson('/api/v1/auth/login', [
            'usernameOrPhone' => 'test.user@example.com',
            'password' => 'password123',
        ])->assertStatus(200);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        $this->postJson('/api/v1/auth/login', [
            'usernameOrPhone' => '9876543210',
            'password' => 'wrongpassword',
        ])->assertStatus(401);
    }

    public function test_deleted_user_cannot_login(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertStatus(201);

        $user = User::where('phone', '9876543210')->first();
        $user->delete();

        $this->postJson('/api/v1/auth/login', [
            'usernameOrPhone' => '9876543210',
            'password' => 'password123',
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_delete_me_requires_password(): void
    {
        $register = $this->postJson('/api/v1/auth/register', $this->registerPayload())
            ->assertStatus(201);

        $token = $register->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/auth/me', ['password' => 'wrongpassword'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_delete_me_schedules_seven_day_wait_without_wiping(): void
    {
        $register = $this->postJson('/api/v1/auth/register', $this->registerPayload())
            ->assertStatus(201);

        $token = $register->json('data.accessToken');
        $userId = $register->json('data.user.id');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/auth/me', ['password' => 'password123'])
            ->assertStatus(200)
            ->assertJsonPath('data.pending', true)
            ->assertJsonPath('data.daysRemaining', 7);

        $user = User::find($userId);
        $this->assertNotNull($user);
        $this->assertNull($user->deleted_at);
        $this->assertEquals('Test User', $user->full_name);
        $this->assertEquals('9876543210', $user->phone);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.accountDeletion.pending', true);
    }

    public function test_cancel_deletion_clears_pending_request(): void
    {
        $register = $this->postJson('/api/v1/auth/register', $this->registerPayload())
            ->assertStatus(201);

        $token = $register->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/auth/me', ['password' => 'password123'])
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/me/cancel-deletion')
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.accountDeletion', null);
    }

    public function test_due_app_deletion_is_processed_after_seven_days(): void
    {
        $register = $this->postJson('/api/v1/auth/register', $this->registerPayload())
            ->assertStatus(201);

        $token = $register->json('data.accessToken');
        $userId = $register->json('data.user.id');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/auth/me', ['password' => 'password123'])
            ->assertStatus(200);

        $this->artisan('accounts:process-deletions')->assertSuccessful();
        $this->assertNull(User::find($userId)->deleted_at);

        $this->travel(7)->days();
        $this->artisan('accounts:process-deletions')->assertSuccessful();

        $user = User::withTrashed()->find($userId);
        $this->assertNotNull($user->deleted_at);
        $this->assertEquals('Deleted User', $user->full_name);
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
