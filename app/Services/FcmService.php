<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Throwable;

class FcmService
{
    protected ?Messaging $messaging = null;

    protected bool $resolved = false;

    public function isReady(): bool
    {
        return $this->messaging() !== null;
    }

    /**
     * Send FCM notification to all device tokens of a user.
     * If FCM is disabled or credentials are missing, this logs and returns.
     */
    public function sendToUser(string $userId, string $title, string $body, array $data = []): void
    {
        $tokens = DeviceToken::where('user_id', $userId)->pluck('token')->filter()->unique()->values()->all();

        $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * @param  list<string>  $tokens
     * @return array{sent: int, failed: int}
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens, fn ($token) => is_string($token) && $token !== '')));

        if ($tokens === []) {
            return ['sent' => 0, 'failed' => 0];
        }

        $messaging = $this->messaging();
        if ($messaging === null) {
            Log::info('FCM is disabled or not configured. Skipping notification.', [
                'title' => $title,
                'token_count' => count($tokens),
            ]);

            return ['sent' => 0, 'failed' => 0];
        }

        $payload = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $payload[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        $message = CloudMessage::new()
            ->withNotification(FcmNotification::create($title, $body))
            ->withData($payload)
            ->withHighestPossiblePriority();

        try {
            $report = $messaging->sendMulticast($message, $tokens);
            $unknown = $report->unknownTokens();
            $invalid = $report->invalidTokens();
            $dead = array_values(array_unique(array_merge($unknown, $invalid)));

            if ($dead !== []) {
                DeviceToken::whereIn('token', $dead)->delete();
                Log::warning('FCM pruned invalid tokens', [
                    'count' => count($dead),
                ]);
            }

            return [
                'sent' => $report->successes()->count(),
                'failed' => $report->failures()->count(),
            ];
        } catch (Throwable $e) {
            Log::error('FCM multicast failed', [
                'error' => $e->getMessage(),
                'token_count' => count($tokens),
            ]);

            return ['sent' => 0, 'failed' => count($tokens)];
        }
    }

    protected function messaging(): ?Messaging
    {
        if ($this->resolved) {
            return $this->messaging;
        }

        $this->resolved = true;

        if (! filter_var(config('services.fcm.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $credentials = $this->credentialsPath();
        if ($credentials === '' || ! is_file($credentials)) {
            Log::warning('FCM enabled but service account file is missing', [
                'path' => $credentials,
            ]);

            return null;
        }

        try {
            $this->messaging = app(Messaging::class);
        } catch (Throwable $e) {
            Log::error('FCM client init failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->messaging;
    }

    protected function credentialsPath(): string
    {
        $path = (string) config('services.fcm.credentials', '');
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, '/') || str_contains($path, ':\\')) {
            return $path;
        }

        return base_path($path);
    }
}
