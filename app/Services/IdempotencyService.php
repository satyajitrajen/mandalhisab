<?php

namespace App\Services;

use App\Models\IdempotencyRecord;

class IdempotencyService
{
    /**
     * Check if an idempotent request has already been processed.
     */
    public function check(string $key, string $userId, string $route, string $requestHash): ?array
    {
        $record = IdempotencyRecord::where('idempotency_key', $key)
            ->where('user_id', $userId)
            ->where('route', $route)
            ->where('request_hash', $requestHash)
            ->where('expires_at', '>', now())
            ->first();

        if ($record) {
            return [
                'response_body' => json_decode($record->response_body, true),
            ];
        }

        return null;
    }

    /**
     * Store an idempotency record so the same request can return a cached response.
     */
    public function store(
        string $key,
        string $userId,
        string $route,
        string $requestHash,
        mixed $responseBody,
        int $ttlHours = 24
    ): IdempotencyRecord {
        return IdempotencyRecord::create([
            'idempotency_key' => $key,
            'user_id' => $userId,
            'route' => $route,
            'request_hash' => $requestHash,
            'response_body' => json_encode($responseBody),
            'expires_at' => now()->addHours($ttlHours),
        ]);
    }
}
