<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendFcmNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Notification $notification)
    {
        $this->onQueue('notifications');
    }

    public function handle(FcmService $fcmService): void
    {
        if (! $this->notification->user_id) {
            return;
        }

        $type = $this->notification->type;
        $typeValue = $type instanceof \BackedEnum ? $type->value : (string) $type;

        $fcmService->sendToUser(
            $this->notification->user_id,
            $this->notification->title,
            $this->notification->body,
            [
                'type' => $typeValue,
                'referenceId' => (string) ($this->notification->reference_id ?? ''),
                'notificationId' => (string) $this->notification->id,
            ]
        );
    }
}
