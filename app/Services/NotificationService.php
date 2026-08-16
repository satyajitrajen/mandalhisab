<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\Bus;

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

        if (config('services.fcm.enabled', false)) {
            Bus::dispatch(new \App\Jobs\SendFcmNotification($notification));
        }

        return $notification;
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
}
