<?php

namespace App\Services;

use App\Models\AccountDeletionRequest;
use App\Models\DeviceToken;
use App\Models\IdempotencyRecord;
use App\Models\MandalMember;
use App\Models\Notification;
use App\Models\PasswordResetToken;
use App\Models\RefreshSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AccountDeletionService
{
    /**
     * Submit a public account-deletion request from the web portal.
     * No authentication required. Creates a PENDING record.
     */
    public function submitPublicRequest(string $phoneOrUsername, ?string $mandalName, ?string $reason): AccountDeletionRequest
    {
        $normalized = strtolower(trim($phoneOrUsername));
        $phone = $this->extractPhone($normalized);

        // Try to link to an existing user
        $user = User::where(function ($q) use ($normalized, $phone) {
            $q->whereRaw('LOWER(username) = ?', [$normalized]);
            if ($phone) {
                $q->orWhere('phone', $phone);
            }
        })->first();

        return AccountDeletionRequest::create([
            'phone_or_username' => $phoneOrUsername,
            'mandal_name' => $mandalName,
            'reason' => $reason,
            'status' => 'PENDING',
            'user_id' => $user?->id,
        ]);
    }

    /**
     * Permanently delete (anonymize) an authenticated user account.
     *
     * 1. Validates password.
     * 2. Soft-deletes the user and anonymizes PII.
     * 3. Deactivates all mandal memberships.
     * 4. Hard-deletes auth artifacts (sessions, tokens, notifications).
     * 5. Anonymizes idempotency records.
     * 6. Marks any pending deletion request as COMPLETED.
     * 7. Invalidates festival caches.
     */
    public function deleteAuthenticatedUser(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw new \Exception('Current password is incorrect');
        }

        DB::transaction(function () use ($user) {
            // 1. Anonymize user record and soft-delete
            $updateData = [
                'full_name' => 'Deleted User',
                'username' => null,
                'phone' => null,
                'email' => null,
                'password' => Hash::make(bin2hex(random_bytes(32))),
                'security_pin' => null,
                'avatar_url' => null,
                'is_biometric_enabled' => false,
                'active_festival_id' => null,
            ];

            $user->update($updateData);

            // Delete avatar file from storage if it existed
            if (! empty($user->getOriginal('avatar_url'))) {
                $path = str_replace(asset('storage/') . '/', '', $user->getOriginal('avatar_url'));
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }

            $user->delete(); // soft delete (sets deleted_at)

            // 2. Deactivate all mandal memberships
            MandalMember::where('user_id', $user->id)
                ->update(['is_active' => false]);

            // 3. Hard-delete auth artifacts
            RefreshSession::where('user_id', $user->id)->delete();
            DeviceToken::where('user_id', $user->id)->delete();
            Notification::where('user_id', $user->id)->delete();
            PasswordResetToken::where('user_id', $user->id)->delete();

            // 4. Anonymize idempotency records (remove user link)
            IdempotencyRecord::where('user_id', $user->id)
                ->update(['user_id' => null]);

            // 5. Mark pending deletion requests as COMPLETED
            AccountDeletionRequest::where('user_id', $user->id)
                ->where('status', 'PENDING')
                ->update(['status' => 'COMPLETED', 'completed_at' => now()]);

            // 6. Invalidate caches for all festivals the user was part of
            $festivalIds = MandalMember::where('user_id', $user->id)
                ->with('mandal.festivals')
                ->get()
                ->flatMap(fn ($mm) => $mm->mandal->festivals->pluck('id'))
                ->unique()
                ->values();

            foreach ($festivalIds as $festivalId) {
                CacheKeyService::clearDashboardAndFunds($festivalId);
            }
        });
    }

    protected function extractPhone(string $input): ?string
    {
        $digits = preg_replace('/\D/', '', $input);
        return (strlen($digits) === 10) ? $digits : null;
    }
}
