<?php

namespace App\Services;

use App\Enums\HandoverStatus;
use App\Enums\MemberRole;
use App\Enums\NotificationType;
use App\Jobs\SendFcmNotification;
use App\Models\CashHandover;
use App\Models\ExpenseEntry;
use App\Models\Festival;
use App\Models\MandalMember;
use App\Models\Notification;
use App\Models\User;
use App\Models\VarganiEntry;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    /**
     * Create a notification and optionally dispatch FCM job.
     */
    public function createNotification(
        ?string $userId,
        ?string $mandalId,
        ?string $festivalId,
        string $title,
        string $body,
        string $type,
        ?string $referenceId = null
    ): Notification {
        $notification = Notification::create([
            'user_id' => $userId,
            'mandal_id' => $mandalId,
            'festival_id' => $festivalId,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'reference_id' => $referenceId,
            'is_read' => false,
        ]);

        if (filter_var(config('services.fcm.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            try {
                Bus::dispatch(new SendFcmNotification($notification));
            } catch (Throwable $e) {
                Log::error('FCM job dispatch failed', [
                    'notification_id' => $notification->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $notification;
    }

    public function notifyVarganiCreated(VarganiEntry $entry): void
    {
        $this->notifyOfficers(
            mandalId: (string) $entry->mandal_id,
            festivalId: (string) $entry->festival_id,
            exceptUserId: $entry->collector_id,
            title: 'New Vargani Received',
            body: sprintf(
                '%s paid %s (receipt #%s).',
                $entry->donor_name,
                $this->rupees((float) $entry->amount),
                $entry->receipt_number
            ),
            type: NotificationType::VARGANI_CREATED,
            referenceId: $entry->id,
        );
    }

    public function notifyExpenseCreated(ExpenseEntry $expense): void
    {
        $festival = Festival::query()->find($expense->festival_id);
        if (! $festival) {
            return;
        }

        $paidTo = $expense->paid_to ? ' paid to '.$expense->paid_to : '';

        $this->notifyOfficers(
            mandalId: (string) $festival->mandal_id,
            festivalId: (string) $expense->festival_id,
            exceptUserId: $expense->created_by_user_id,
            title: 'Expense Recorded',
            body: sprintf(
                '%s — %s%s.',
                $expense->title,
                $this->rupees((float) $expense->amount),
                $paidTo
            ),
            type: NotificationType::EXPENSE_CREATED,
            referenceId: $expense->id,
        );
    }

    public function notifyHandoverInitiated(CashHandover $handover): void
    {
        if (! $handover->to_user_id || $handover->to_user_id === $handover->from_user_id) {
            return;
        }

        $festival = Festival::query()->find($handover->festival_id);

        $this->notifyUsers(
            userIds: [$handover->to_user_id],
            mandalId: $festival?->mandal_id,
            festivalId: (string) $handover->festival_id,
            title: 'Cash Handover Submitted',
            body: sprintf(
                '%s submitted %s for verification.',
                $this->userName($handover->from_user_id),
                $this->rupees((float) $handover->amount)
            ),
            type: NotificationType::HANDOVER_INITIATED,
            referenceId: $handover->id,
        );
    }

    public function notifyHandoverVerified(CashHandover $handover): void
    {
        if (! $handover->from_user_id) {
            return;
        }

        $accepted = $handover->status === HandoverStatus::VERIFIED_ACCEPTED;

        $festival = Festival::query()->find($handover->festival_id);
        $verifier = $this->userName($handover->to_user_id);

        $this->notifyUsers(
            userIds: [$handover->from_user_id],
            mandalId: $festival?->mandal_id,
            festivalId: (string) $handover->festival_id,
            title: $accepted ? 'Cash Handover Approved' : 'Cash Handover Rejected',
            body: $accepted
                ? sprintf('%s accepted %s cash collection.', $verifier, $this->rupees((float) $handover->amount))
                : sprintf('%s rejected the %s handover.', $verifier, $this->rupees((float) $handover->amount)),
            type: $accepted ? NotificationType::HANDOVER_APPROVED : NotificationType::HANDOVER_REJECTED,
            referenceId: $handover->id,
        );
    }

    public function notifyFinalHisabLocked(Festival $festival): void
    {
        $userIds = MandalMember::query()
            ->where('mandal_id', $festival->mandal_id)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        $this->notifyUsers(
            userIds: $userIds,
            mandalId: (string) $festival->mandal_id,
            festivalId: (string) $festival->id,
            title: 'Final Hisab Locked',
            body: sprintf('%s is signed and locked.', $festival->name),
            type: NotificationType::FINAL_HISAB_SIGNED,
            referenceId: $festival->id,
        );
    }

    public function notifyFinalHisabUnlocked(Festival $festival): void
    {
        $userIds = MandalMember::query()
            ->where('mandal_id', $festival->mandal_id)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        $this->notifyUsers(
            userIds: $userIds,
            mandalId: (string) $festival->mandal_id,
            festivalId: (string) $festival->id,
            title: 'Final Hisab Unlocked',
            body: sprintf('%s was unlocked by the mandal owner. Entries can be corrected.', $festival->name),
            type: NotificationType::FINAL_HISAB_SIGNED,
            referenceId: $festival->id,
        );
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(string $notificationId): Notification
    {
        $notification = Notification::findOrFail($notificationId);
        $notification->is_read = true;
        $notification->save();

        return $notification;
    }

    /**
     * Mark all notifications for a user as read.
     */
    public function markAllRead(string $userId): void
    {
        Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }

    /**
     * @param  list<string|null>  $userIds
     */
    protected function notifyUsers(
        array $userIds,
        ?string $mandalId,
        ?string $festivalId,
        string $title,
        string $body,
        NotificationType $type,
        ?string $referenceId = null
    ): void {
        $userIds = array_values(array_unique(array_filter($userIds)));

        foreach ($userIds as $userId) {
            try {
                $this->createNotification(
                    $userId,
                    $mandalId,
                    $festivalId,
                    $title,
                    $body,
                    $type->value,
                    $referenceId
                );
            } catch (Throwable $e) {
                Log::error('Failed to create notification', [
                    'user_id' => $userId,
                    'type' => $type->value,
                    'reference_id' => $referenceId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function notifyOfficers(
        string $mandalId,
        string $festivalId,
        ?string $exceptUserId,
        string $title,
        string $body,
        NotificationType $type,
        ?string $referenceId
    ): void {
        $query = MandalMember::query()
            ->where('mandal_id', $mandalId)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->whereIn('role', [
                MemberRole::SUPER_ADMIN,
                MemberRole::ADMIN,
                MemberRole::TREASURER,
            ]);

        if ($exceptUserId) {
            $query->where('user_id', '!=', $exceptUserId);
        }

        $this->notifyUsers(
            userIds: $query->pluck('user_id')->all(),
            mandalId: $mandalId,
            festivalId: $festivalId,
            title: $title,
            body: $body,
            type: $type,
            referenceId: $referenceId,
        );
    }

    protected function userName(?string $userId): string
    {
        if (! $userId) {
            return 'Member';
        }

        return User::query()->whereKey($userId)->value('full_name') ?: 'Member';
    }

    protected function rupees(float $amount): string
    {
        $formatted = fmod($amount, 1.0) === 0.0
            ? number_format($amount, 0)
            : number_format($amount, 2);

        return '₹'.$formatted;
    }
}
