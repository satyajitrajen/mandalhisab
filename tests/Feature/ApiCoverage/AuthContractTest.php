<?php

namespace Tests\Feature\ApiCoverage;

use App\Enums\MemberRole;
use App\Http\Middleware\RateLimit;
use App\Mail\PasswordResetLinkMail;
use App\Models\MandalMember;
use App\Models\RefreshSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
        $this->withoutMiddleware(RateLimit::class);
        Mail::fake();

        $user = User::factory()->create([
            'password' => 'old-password-1',
            'phone' => '9876543210',
            'email' => 'reset.me@example.com',
        ]);

        $this->postJson('/api/v1/auth/forgot-password', [
            'usernameOrPhone' => '9876543210',
        ])->assertStatus(200)
            ->assertJsonPath('data.sentTo', 'r***@example.com')
            ->assertJsonMissingPath('data.token');

        $token = null;
        Mail::assertSent(PasswordResetLinkMail::class, function (PasswordResetLinkMail $mail) use (&$token, $user) {
            $token = $mail->token;
            return $mail->hasTo($user->email) && strlen($mail->token) === 64;
        });
        $this->assertIsString($token);

        // Wrong token -> 422.
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => str_repeat('0', 64),
            'newPassword' => 'brand-new-pass-1',
        ])->assertStatus(422);

        // Correct token -> password changed.
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'newPassword' => 'brand-new-pass-1',
        ])->assertStatus(200);

        $this->assertTrue(password_verify('brand-new-pass-1', User::find($user->id)->password));

        // Token is single-use -> reusing it fails.
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
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
        $this->withoutMiddleware(RateLimit::class);
        $this->postJson('/api/v1/auth/forgot-password', [
            'usernameOrPhone' => '9999999999',
        ])->assertStatus(404);
    }

    public function test_forgot_password_accepts_email_and_requires_one(): void
    {
        $this->withoutMiddleware(RateLimit::class);
        Mail::fake();

        $user = User::factory()->create([
            'phone' => '9876501122',
            'email' => 'by.email@example.com',
        ]);

        $this->postJson('/api/v1/auth/forgot-password', [
            'usernameOrPhone' => 'by.email@example.com',
        ])->assertStatus(200);

        Mail::assertSent(PasswordResetLinkMail::class, fn (PasswordResetLinkMail $mail) => $mail->hasTo($user->email));

        User::factory()->create(['phone' => '9876501133', 'email' => null]);

        $this->postJson('/api/v1/auth/forgot-password', [
            'usernameOrPhone' => '9876501133',
        ])->assertStatus(422);
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