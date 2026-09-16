<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DeviceToken;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class DeviceController
{
    use ApiResponse;

    /**
     * PUT /api/v1/devices/token
     *
     * Register or update device token for push notifications.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'deviceToken' => ['nullable', 'string', 'max:500'],
            'token' => ['nullable', 'string', 'max:500'],
            'platform' => ['nullable', 'in:android,ios,web,windows'],
            'deviceId' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $token = $validated['deviceToken'] ?? $validated['token'] ?? '';
        if ($token === '') {
            return $this->error('VALIDATION_FAILED', 'A device token is required', 422);
        }
        $platform = $validated['platform'] ?? 'android';

        // Upsert: update existing token for this user+platform, or create new
        $existing = DeviceToken::where('user_id', $user->id)
            ->where('platform', $platform)
            ->first();

        if ($existing) {
            $existing->update([
                'token' => $token,
                'last_seen_at' => now(),
            ]);
            $record = $existing;
        } else {
            $record = DeviceToken::create([
                'user_id' => $user->id,
                'token' => $token,
                'platform' => $platform,
                'last_seen_at' => now(),
            ]);
        }

        return $this->success([
            'id' => $record->id,
            'platform' => $record->platform,
            'lastSeenAt' => $record->last_seen_at?->toIso8601String(),
        ], 'Device registered successfully');
    }
}
