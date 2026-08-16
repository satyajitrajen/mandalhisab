<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;

class FcmService
{
    protected ?Messaging $messaging = null;

    public function __construct()
    {
        if (config('services.fcm.enabled', false)) {
            $this->messaging = app(Messaging::class);
        }
    }

    /**
     * Send FCM notification to all device tokens of a user.
     * If FCM is disabled, this method logs and returns immediately.
     */
    public function sendToUser(string $userId, string $title, string $body, array $data = []): void
    {
        if (! config('services.fcm.enabled', false) || ! $this->messaging) {
            Log::info('FCM is disabled. Skipping notification.', [
                'user_id' => $userId,
                'title' => $title,
            ]);

            return;
        }

        $tokens = DeviceToken::where('user_id', $userId)->pluck('token')->toArray();

        if (empty($tokens)) {
            return;
        }

        foreach ($tokens as $token) {
            $message = CloudMessage::withTarget('token', $token)
                ->withNotification(['title' => $title, 'body' => $body])
                ->withData($data);

            try {
                $this->messaging->send($message);
            } catch (\Throwable $e) {
                Log::error('FCM send failed', [
                    'token' => $token,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
