<?php

namespace App\Services;

use App\Models\StreamedEvent;
use Illuminate\Support\Facades\DB;

class EventStreamService
{
    /**
     * Store a new event in the stream.
     */
    public function storeEvent(string $festivalId, string $channel, string $eventType, array $payload): StreamedEvent
    {
        return StreamedEvent::create([
            'festival_id' => $festivalId,
            'channel' => $channel,
            'event_type' => $eventType,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    /**
     * Get events missed after a specific event ID.
     */
    public function getMissedEvents(string $festivalId, string $lastEventId): array
    {
        $last = StreamedEvent::find($lastEventId);

        $query = StreamedEvent::where('festival_id', $festivalId);

        if ($last) {
            $query->where('created_at', '>', $last->created_at);
        }

        return $query->orderBy('created_at')->get()->toArray();
    }

    /**
     * Poll for newer events after a given event ID.
     */
    public function pollForEvents(string $festivalId, ?string $lastEventId, int $limit = 50): array
    {
        $query = StreamedEvent::where('festival_id', $festivalId);

        if ($lastEventId) {
            $last = StreamedEvent::find($lastEventId);
            if ($last) {
                $query->where('created_at', '>', $last->created_at);
            }
        }

        return $query->orderBy('created_at')->limit($limit)->get()->toArray();
    }

    /**
     * Cleanup events older than N hours.
     */
    public function cleanupOldEvents(int $hours = 24): int
    {
        $cutoff = now()->subHours($hours);

        return StreamedEvent::where('created_at', '<', $cutoff)->delete();
    }
}
