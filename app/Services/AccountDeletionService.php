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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AccountDeletionService
{
    public const SOURCE_APP = 'APP';
    public const SOURCE_WEB = 'WEB';

    public function graceDays(): int
    {
        return max(1, (int) config('app.account_deletion_grace_days', 7));
    }

    public function pendingFor(User $user): ?AccountDeletionRequest
    {
        return AccountDeletionRequest::query()
            ->where('user_id', $user->id)
            ->where('status', 'PENDING')
            ->latest()
            ->first();
    }

    public function formatPending(?AccountDeletionRequest $request): ?array
    {
        if ($request === null || $request->status !== 'PENDING') {
            return null;
        }

        return [
            'pending' => true,
            'scheduledFor' => $request->scheduled_for?->toIso8601String(),
            'daysRemaining' => $this->daysRemaining($request),
        ];
    }

    /**
     * Submit a public account-deletion request from the web portal.
     * Creates a PENDING record. Web requests are not auto-purged — no password proof.
     */
    public function submitPublicRequest(string $phoneOrUsername, ?string $mandalName, ?string $reason): AccountDeletionRequest
    {
        $normalized = strtolower(trim($phoneOrUsername));
        $phone = $this->extractPhone($normalized);

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
            'source' => self::SOURCE_WEB,
            'user_id' => $user?->id,
            'scheduled_for' => now()->addDays($this->graceDays()),
        ]);
    }

    /**
     * Schedule in-app deletion after the grace period. Password-verified.
     * Does not wipe the account until processDueDeletions() runs.
     */
    public function scheduleAuthenticatedDeletion(User $user, string $password): array
    {
        if (! Hash::check($password, $user->password)) {
            throw new \Exception('Current password is incorrect');
        }

        $existing = $this->pendingFor($user);
        if ($existing !== null) {
            return $this->formatPending($existing) ?? [];
        }

        $request = AccountDeletionRequest::create([
            'phone_or_username' => $user->phone ?? $user->username ?? $user->id,
            'status' => 'PENDING',
            'source' => self::SOURCE_APP,
            'user_id' => $user->id,
            'scheduled_for' => now()->addDays($this->graceDays()),
        ]);

        Log::info('Account deletion scheduled', [
            'user_id' => $user->id,
            'request_id' => $request->id,
            'scheduled_for' => $request->scheduled_for?->toIso8601String(),
        ]);

        return $this->formatPending($request) ?? [];
    }

    public function cancelAuthenticatedDeletion(User $user): void
    {
        $pending = $this->pendingFor($user);
        if ($pending === null) {
            throw new \Exception('No pending deletion request');
        }

        $pending->update([
            'status' => 'CANCELLED',
            'cancelled_at' => now(),
        ]);

        Log::info('Account deletion cancelled', [
            'user_id' => $user->id,
            'request_id' => $pending->id,
        ]);
    }

    /**
     * Permanently anonymize password-verified APP requests whose wait has ended.
     */
    public function processDueDeletions(): int
    {
        $due = AccountDeletionRequest::query()
            ->where('status', 'PENDING')
            ->where('source', self::SOURCE_APP)
            ->whereNotNull('user_id')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->get();

        $count = 0;
        foreach ($due as $request) {
            $user = User::find($request->user_id);
            if ($user === null) {
                $request->update([
                    'status' => 'COMPLETED',
                    'completed_at' => now(),
                ]);
                continue;
            }

            $this->purgeUser($user, $request);
            $count++;
        }

        return $count;
    }

    /**
     * Anonymize PII, soft-delete the user, keep mandal financial history.
     */
    public function purgeUser(User $user, ?AccountDeletionRequest $request = null): void
    {
        DB::transaction(function () use ($user, $request) {
            $avatarUrl = $user->avatar_url;

            $user->update([
                'full_name' => 'Deleted User',
                'username' => null,
                'phone' => null,
                'email' => null,
                'password' => Hash::make(bin2hex(random_bytes(32))),
                'security_pin' => null,
                'avatar_url' => null,
                'is_biometric_enabled' => false,
                'active_festival_id' => null,
            ]);

            if (! empty($avatarUrl)) {
                $path = str_replace(asset('storage/').'/', '', $avatarUrl);
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }

            $user->delete();

            MandalMember::where('user_id', $user->id)
                ->update(['is_active' => false]);

            RefreshSession::where('user_id', $user->id)->delete();
            DeviceToken::where('user_id', $user->id)->delete();
            Notification::where('user_id', $user->id)->delete();
            PasswordResetToken::where('user_id', $user->id)->delete();

            IdempotencyRecord::where('user_id', $user->id)
                ->update(['user_id' => null]);

            AccountDeletionRequest::where('user_id', $user->id)
                ->where('status', 'PENDING')
                ->update(['status' => 'COMPLETED', 'completed_at' => now()]);

            $festivalIds = MandalMember::where('user_id', $user->id)
                ->with('mandal.festivals')
                ->get()
                ->flatMap(fn ($mm) => $mm->mandal->festivals->pluck('id'))
                ->unique()
                ->values();

            foreach ($festivalIds as $festivalId) {
                CacheKeyService::clearDashboardAndFunds($festivalId);
            }

            Log::info('Account deletion completed', [
                'user_id' => $user->id,
                'request_id' => $request?->id,
            ]);
        });
    }

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

    private function daysRemaining(AccountDeletionRequest $request): int
    {
        if ($request->scheduled_for === null || $request->scheduled_for->isPast()) {
            return 0;
        }

        return max(1, (int) ceil(now()->diffInSeconds($request->scheduled_for) / 86400));
    }
}
