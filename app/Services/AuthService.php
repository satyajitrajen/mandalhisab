<?php

namespace App\Services;

use App\Enums\MemberRole;
use App\Models\DeviceToken;
use App\Models\Mandal;
use App\Models\MandalMember;
use App\Models\PasswordResetToken;
use App\Models\RefreshSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
            $username = $phone ? null : strtolower(trim($input));

            $user = User::create([
                'full_name' => $data['fullName'],
                'username' => $username,
                'phone' => $phone,
                'email' => $data['email'] ?? null,
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
        $input = strtolower(trim($data['usernameOrPhone']));
        $phone = $this->extractPhone($input);

        $user = User::where(function ($q) use ($input, $phone) {
            $q->whereRaw('LOWER(username) = ?', [$input]);
            if ($phone) {
                $q->orWhere('phone', $phone);
            }
        })->first();

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
        $valid = false;

        if (! empty($validation['currentPassword'])) {
            $valid = Hash::check($validation['currentPassword'], $user->password);
        } elseif (! empty($validation['currentPin'])) {
            $valid = Hash::check($validation['currentPin'], $user->security_pin);
        }

        if (! $valid) {
            throw new \Exception('Current password or PIN is incorrect');
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
     * Start a password reset: issue a 6-digit OTP for the account's
     * username or phone. The OTP is stored hashed and expires in 10 minutes.
     *
     * Note: with no SMS gateway wired up, the OTP is returned in the response
     * and logged. Swap `return $otp` for a real SMS/email send in production.
     */
    public function forgotPassword(string $usernameOrPhone): array
    {
        $input = strtolower(trim($usernameOrPhone));
        $phone = $this->extractPhone($input);

        $user = User::where(function ($q) use ($input, $phone) {
            $q->whereRaw('LOWER(username) = ?', [$input]);
            if ($phone) {
                $q->orWhere('phone', $phone);
            }
        })->first();

        if (! $user) {
            throw new \Exception('No account found for this username or phone');
        }

        // Invalidate any previous outstanding OTP for this user.
        PasswordResetToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $otp = (string) random_int(100000, 999999);

        PasswordResetToken::create([
            'user_id' => $user->id,
            'token_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(10),
        ]);

        \Illuminate\Support\Facades\Log::info('Password reset OTP for ' . ($user->username ?? $user->phone) . ": {$otp}");

        return [
            'expiresInMinutes' => 10,
            'otp' => $otp,
        ];
    }

    /**
     * Complete a password reset with the OTP from [forgotPassword].
     */
    public function resetPassword(string $usernameOrPhone, string $otp, string $newPassword): void
    {
        $input = strtolower(trim($usernameOrPhone));
        $phone = $this->extractPhone($input);

        $user = User::where(function ($q) use ($input, $phone) {
            $q->whereRaw('LOWER(username) = ?', [$input]);
            if ($phone) {
                $q->orWhere('phone', $phone);
            }
        })->first();

        if (! $user) {
            throw new \Exception('No account found for this username or phone');
        }

        $record = PasswordResetToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();

        if (! $record || ! Hash::check($otp, $record->token_hash)) {
            throw new \Exception('Invalid or expired OTP');
        }

        $record->update(['used_at' => now()]);
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
        $digits = preg_replace('/\D/', '', $input);
        if (strlen($digits) === 10) {
            return $digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return substr($digits, 2);
        }
        if (strlen($digits) > 10) {
            return substr($digits, -10);
        }
        return null;
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
        return [
            'id' => $user->id,
            'name' => $user->full_name,
            'phone' => $user->phone ? '+91' . $user->phone : null,
            'email' => $user->email,
            'avatarUrl' => $user->avatar_url,
            'initials' => $user->initials,
            'defaultLanguage' => $user->default_language,
            'isBiometricEnabled' => $user->is_biometric_enabled,
            'activeFestivalId' => $user->active_festival_id,
            'mandals' => $user->mandalMembers->map(fn ($mm) => [
                'id' => $mm->mandal_id,
                'name' => $mm->mandal->name ?? null,
                'role' => $mm->role->value,
                'isDefault' => $mm->is_default,
            ])->toArray(),
        ];
    }

    protected function storeAvatar(string $base64): string
    {
        $data = base64_decode(explode(',', $base64)[1] ?? $base64);
        $path = 'avatars/' . uniqid() . '.png';
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $data);
        return asset('storage/' . $path);
    }
}
