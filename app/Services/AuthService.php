<?php

namespace App\Services;

use App\Enums\MemberRole;
use App\Mail\PasswordResetLinkMail;
use App\Models\DeviceToken;
use App\Models\Mandal;
use App\Models\MandalMember;
use App\Models\PasswordResetToken;
use App\Models\RefreshSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthService
{
    /**
     * Register a new user and optionally create a mandal.
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $input = $data['usernameOrPhone'];
            $phone = $this->extractPhone($input);
            if (! $phone) {
                throw new \InvalidArgumentException('Enter a valid 10-digit Indian mobile number starting with 6, 7, 8, or 9.');
            }

            $email = strtolower(trim((string) ($data['email'] ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('A valid email is required.');
            }

            $user = User::create([
                'full_name' => $data['fullName'],
                'username' => $phone,
                'phone' => $phone,
                'email' => $email,
                'password' => $data['password'],
                'default_language' => $data['defaultLanguage'] ?? 'en',
            ]);

            $mandal = null;
            if (! empty($data['mandalName'])) {
                $mandal = Mandal::create([
                    'name' => $data['mandalName'],
                    'address' => $data['address'] ?? 'Address TBD',
                    'city' => $data['city'] ?? 'City TBD',
                    'pincode' => $data['pincode'] ?? '000000',
                    'contact_number' => $phone ?? '0000000000',
                    'created_by_user_id' => $user->id,
                ]);

                MandalMember::create([
                    'mandal_id' => $mandal->id,
                    'user_id' => $user->id,
                    'role' => MemberRole::ADMIN,
                    'is_default' => true,
                    'is_active' => true,
                    'joined_at' => now(),
                ]);
            }

            if (! empty($data['deviceToken'])) {
                $this->registerDevice($user->id, $data['deviceToken'], $data['platform'] ?? 'android');
            }

            $tokens = $this->issueTokens($user);

            $user->load('mandalMembers.mandal');

            return [
                'user' => $this->formatUser($user),
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_in' => config('jwt.ttl', 60) * 60,
            ];
        });
    }

    /**
     * Authenticate and issue tokens.
     */
    public function login(array $data): array
    {
        $user = $this->findAccount((string) $data['usernameOrPhone']);

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new \Exception('Invalid credentials');
        }

        if ($user->deleted_at !== null) {
            throw new \Exception('Account has been deleted');
        }

        if (! empty($data['deviceToken'])) {
            $this->registerDevice($user->id, $data['deviceToken'], $data['platform'] ?? 'android');
        }

        $tokens = $this->issueTokens($user);
        $user->load('mandalMembers.mandal');

        return [
            'user' => $this->formatUser($user),
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => config('jwt.ttl', 60) * 60,
        ];
    }

    /**
     * Rotate refresh token.
     */
    public function refresh(string $refreshToken): array
    {
        $hash = hash('sha256', $refreshToken);

        $session = RefreshSession::where('refresh_token_hash', $hash)
            ->where('expires_at', '>', now())
            ->first();

        if (! $session) {
            throw new \Exception('Invalid or expired refresh token');
        }

        $user = $session->user;

        if (! $user || $user->deleted_at !== null) {
            $session->delete();
            throw new \Exception('Account has been deleted');
        }

        $session->delete();

        return $this->issueTokens($user);
    }

    /**
     * Logout: invalidate refresh session and current JWT.
     */
    public function logout(?string $refreshToken, bool $allSessions = false): void
    {
        $user = auth('api')->user();

        if ($refreshToken) {
            $hash = hash('sha256', $refreshToken);
            $session = RefreshSession::where('refresh_token_hash', $hash)->first();

            if ($session) {
                if ($allSessions) {
                    RefreshSession::where('user_id', $session->user_id)->delete();
                } else {
                    $session->delete();
                }
            }
        } elseif ($user && $allSessions) {
            RefreshSession::where('user_id', $user->id)->delete();
        }

        auth('api')->logout();
    }

    /**
     * Set or update the user's security PIN.
     */
    public function setPin(User $user, string $pin, array $validation): void
    {
        $hasExistingPin = ! empty($user->security_pin);
        $valid = false;

        if (! empty($validation['currentPassword'])) {
            $valid = Hash::check($validation['currentPassword'], $user->password);
        } elseif ($hasExistingPin && ! empty($validation['currentPin'])) {
            $valid = Hash::check($validation['currentPin'], $user->security_pin);
        }

        if (! $valid) {
            throw new \Exception(
                $hasExistingPin
                    ? 'Current password or PIN is incorrect'
                    : 'Account password is required to set a PIN for the first time'
            );
        }

        $user->security_pin = $pin;
        $user->save();
    }

    /**
     * Change password after verifying the current one.
     */
    public function changePassword(User $user, string $current, string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            throw new \Exception('Current password is incorrect');
        }

        $user->password = $new;
        $user->save();
    }

    /**
     * Start a password reset: issue a single-use token and email a reset link.
     * The raw token is never returned in the API response.
     */
    public function forgotPassword(string $usernameOrPhone): array
    {
        $user = $this->findAccount($usernameOrPhone);

        if (! $user) {
            throw new \Exception('No account found for this mobile number or email');
        }

        if (! $user->email) {
            throw new \InvalidArgumentException('This account has no email. Add an email on your profile, then try again.');
        }

        PasswordResetToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token = bin2hex(random_bytes(32));

        PasswordResetToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(60),
        ]);

        $resetUrl = rtrim((string) config('app.url'), '/')
            .'/reset-password?token='.$token;

        try {
            Mail::to($user->email)->send(
                new PasswordResetLinkMail($token, $user->full_name ?: 'Member', $resetUrl)
            );
        } catch (Throwable $e) {
            Log::error('Password reset email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Could not send the reset email. Try again or contact support.');
        }

        Log::info('Password reset link emailed', [
            'user_id' => $user->id,
            'sent_to' => $this->maskEmail($user->email),
        ]);

        return [
            'expiresInMinutes' => 60,
            'sentTo' => $this->maskEmail($user->email),
        ];
    }

    /**
     * Complete a password reset with the single-use token from [forgotPassword].
     */
    public function resetPassword(string $token, string $newPassword): void
    {
        $record = PasswordResetToken::where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $record) {
            throw new \Exception('Invalid or expired reset link');
        }

        $user = $record->user;

        if (! $user || $user->deleted_at !== null) {
            throw new \Exception('Invalid or expired reset link');
        }

        $record->update(['used_at' => now()]);

        // Invalidate any other outstanding reset tokens for this account.
        PasswordResetToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $user->password = $newPassword;
        $user->save();

        // Force re-login everywhere: revoke all refresh sessions.
        RefreshSession::where('user_id', $user->id)->delete();
    }

    /**
     * Update user profile fields.
     */
    public function updateProfile(User $user, array $data): User
    {
        if (! empty($data['email'])) {
            $email = strtolower(trim((string) $data['email']));
            $taken = User::query()
                ->where('email', $email)
                ->where('id', '!=', $user->id)
                ->exists();
            if ($taken) {
                throw new \InvalidArgumentException('This email is already registered.');
            }
            $data['email'] = $email;
        }

        $fillable = [
            'full_name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'default_language' => $data['defaultLanguage'] ?? null,
            'is_biometric_enabled' => $data['isBiometricEnabled'] ?? null,
            'active_festival_id' => $data['activeFestivalId'] ?? null,
        ];

        if (! empty($data['avatarBase64'])) {
            $fillable['avatar_url'] = $this->storeAvatar($data['avatarBase64']);
        }

        $user->update(array_filter($fillable, fn ($v) => $v !== null));

        return $user;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function extractPhone(string $input): ?string
    {
        $digits = preg_replace('/\D/', '', $input) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (preg_match('/^[6-9]\d{9}$/', $digits) === 1) {
            return $digits;
        }

        return null;
    }

    public function findAccount(string $input): ?User
    {
        $normalized = strtolower(trim($input));
        $phone = $this->extractPhone($input);
        $email = filter_var($normalized, FILTER_VALIDATE_EMAIL) ?: null;

        return User::query()
            ->where(function ($q) use ($normalized, $phone, $email) {
                $q->whereRaw('LOWER(username) = ?', [$normalized]);
                if ($phone) {
                    $q->orWhere('phone', $phone);
                }
                if ($email) {
                    $q->orWhere('email', $email);
                }
            })
            ->first();
    }

    public function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return '***';
        }

        $visible = substr($local, 0, 1);

        return $visible.'***@'.$domain;
    }

    protected function issueTokens(User $user): array
    {
        $accessToken = JWTAuth::fromUser($user);
        $refreshToken = bin2hex(random_bytes(32));

        RefreshSession::create([
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'expires_at' => now()->addDays(30),
        ]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ];
    }

    protected function registerDevice(string $userId, string $token, string $platform): void
    {
        DeviceToken::updateOrCreate(
            ['user_id' => $userId, 'token' => $token],
            ['platform' => $platform, 'last_seen_at' => now()]
        );
    }

    public function formatUser(User $user): array
    {
        $user->loadMissing('mandalMembers.mandal');

        $unpaidAdmin = $user->mandalMembers->first(function ($mm) {
            return $mm->is_active
                && in_array($mm->role, [MemberRole::ADMIN, MemberRole::SUPER_ADMIN], true)
                && $mm->mandal
                && $mm->mandal->registration_paid_at === null;
        });

        $deletionService = app(AccountDeletionService::class);
        $pendingDeletion = $deletionService->formatPending($deletionService->pendingFor($user));

        return [
            'id' => $user->id,
            'name' => $user->full_name,
            'phone' => $user->phone ? '+91'.$user->phone : null,
            'email' => $user->email,
            'avatarUrl' => $user->avatar_url,
            'initials' => $user->initials,
            'defaultLanguage' => $user->default_language,
            'isBiometricEnabled' => $user->is_biometric_enabled,
            'activeFestivalId' => $user->active_festival_id,
            'accountDeletion' => $pendingDeletion,
            'registrationPaymentRequired' => $unpaidAdmin !== null,
            'unpaidMandal' => $unpaidAdmin ? [
                'id' => $unpaidAdmin->mandal_id,
                'name' => $unpaidAdmin->mandal->name ?? null,
            ] : null,
            'mandals' => $user->mandalMembers->map(fn ($mm) => [
                'id' => $mm->mandal_id,
                'name' => $mm->mandal->name ?? null,
                'role' => $mm->role->value,
                'isDefault' => $mm->is_default,
                'registrationPaid' => $mm->mandal?->registration_paid_at !== null,
            ])->toArray(),
        ];
    }

    protected function storeAvatar(string $base64): string
    {
        $data = base64_decode(explode(',', $base64)[1] ?? $base64);
        $path = 'avatars/'.uniqid().'.png';
        Storage::disk('public')->put($path, $data);

        return asset('storage/'.$path);
    }
}
