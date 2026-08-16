<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Models\MandalMember;
use App\Models\RefreshSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\JwtAuth;
use Tests\TestCase;

class AuthContractTest extends TestCase
{
    use RefreshDatabase, JwtAuth;

    public function test_me_returns_current_user_and_mandals(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::COLLECTOR->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['id', 'name', 'phone', 'email', 'initials', 'defaultLanguage', 'isBiometricEnabled', 'activeFestivalId', 'avatarUrl'],
            ]);
    }

    public function test_update_me_persists_profile_and_active_festival(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->putJson('/api/v1/auth/me', [
                'name' => 'Renamed User',
                'defaultLanguage' => 'mr',
                'activeFestivalId' => $ctx['festival']->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Renamed User')
            ->assertJsonPath('data.defaultLanguage', 'mr');

        $this->assertDatabaseHas('users', [
            'id' => $ctx['user']->id,
            'full_name' => 'Renamed User',
            'active_festival_id' => $ctx['festival']->id,
        ]);
    }

    public function test_security_pin_requires_current_password_and_saves_hashed_pin(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->putJson('/api/v1/auth/security-pin', [
                'pin' => '4321',
                'currentPassword' => 'password',
            ])
            ->assertStatus(200);

        $this->assertNotEquals('4321', User::find($ctx['user']->id)->security_pin);
        $this->assertTrue(password_verify('4321', User::find($ctx['user']->id)->security_pin));
    }

    public function test_security_pin_rejects_wrong_password(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->putJson('/api/v1/auth/security-pin', [
                'pin' => '4321',
                'currentPassword' => 'wrong-password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_change_password_updates_credential(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->putJson('/api/v1/auth/password', [
                'currentPassword' => 'password',
                'newPassword' => 'new-secret-123',
            ])
            ->assertStatus(200);

        $this->assertTrue(password_verify('new-secret-123', User::find($ctx['user']->id)->password));
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $ctx = $this->makeFestivalContext(MemberRole::MEMBER->value);

        $this->withHeaders($this->authHeaders($ctx['user']))
            ->putJson('/api/v1/auth/password', [
                'currentPassword' => 'nope',
                'newPassword' => 'new-secret-123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_logout_blacklists_token_and_deletes_refresh_session(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $tokens = $this->loginAndGetTokens($user);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $tokens['access'],
        ])->postJson('/api/v1/auth/logout', [
            'refreshToken' => $tokens['refresh'],
            'allSessions' => true,
        ])->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('refresh_sessions', [
            'user_id' => $user->id,
        ]);

        // Blacklisted access token must no longer authenticate.
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $tokens['access'],
        ])->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_refresh_rotates_single_use_refresh_token(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $tokens = $this->loginAndGetTokens($user);

        $this->postJson('/api/v1/auth/token/refresh', [
            'refreshToken' => $tokens['refresh'],
        ])
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['accessToken', 'refreshToken', 'expiresIn']])
            ->assertJsonPath('data.expiresIn', config('jwt.ttl', 60) * 60);

        // First token pair is consumed; re-use must fail.
        $this->postJson('/api/v1/auth/token/refresh', [
            'refreshToken' => $tokens['refresh'],
        ])->assertStatus(401);
    }

    public function test_refresh_rejects_garbage_token(): void
    {
        $this->postJson('/api/v1/auth/token/refresh', ['refreshToken' => 'not-a-real-token'])
            ->assertStatus(401);
    }

    public function test_forgot_and_reset_password_flow(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1', 'phone' => '9876543210']);

        $otpResponse = $this->postJson('/api/v1/auth/forgot-password', [
            'usernameOrPhone' => '9876543210',
        ])->assertStatus(200);

        $otp = $otpResponse->json('data.otp');
        $this->assertIsString($otp);
        $this->assertSame(6, strlen($otp));

        // Wrong OTP -> 422.
        $this->postJson('/api/v1/auth/reset-password', [
            'usernameOrPhone' => '9876543210',
            'otp' => '000000',
            'newPassword' => 'brand-new-pass-1',
        ])->assertStatus(422);

        // Correct OTP -> password changed.
        $this->postJson('/api/v1/auth/reset-password', [
            'usernameOrPhone' => '9876543210',
            'otp' => $otp,
            'newPassword' => 'brand-new-pass-1',
        ])->assertStatus(200);

        $this->assertTrue(password_verify('brand-new-pass-1', User::find($user->id)->password));

        // OTP is single-use -> reusing it fails.
        $this->postJson('/api/v1/auth/reset-password', [
            'usernameOrPhone' => '9876543210',
            'otp' => $otp,
            'newPassword' => 'another-pass-123',
        ])->assertStatus(422);

        // Old password no longer works; new one logs in.
        $this->postJson('/api/v1/auth/login', [
            'usernameOrPhone' => '9876543210',
            'password' => 'old-password-1',
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'usernameOrPhone' => '9876543210',
            'password' => 'brand-new-pass-1',
        ])->assertStatus(200);
    }

    public function test_forgot_password_unknown_account_returns_404(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', [
            'usernameOrPhone' => '9999999999',
        ])->assertStatus(404);
    }

    private function loginAndGetTokens(User $user): array
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'usernameOrPhone' => $user->username,
            'password' => 'password',
        ])->assertStatus(200);

        $data = $response->json('data');

        return [
            'access' => $data['accessToken'],
            'refresh' => $data['refreshToken'],
        ];
    }
}