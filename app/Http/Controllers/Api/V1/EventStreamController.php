<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MandalMember;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tymon\JWTAuth\Facades\JWTAuth;

class EventStreamController
{
    use ApiResponse;

    /**
     * SSE stream endpoint.
     *
     * Auth via query param ?token= (SSE EventSource can't set headers) or
     * the Authorization header (jwt.auth middleware runs first).
     * Header: X-Festival-Id (membership is verified against it).
     */
    public function stream(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        // ?token= query auth: EventSource cannot send an Authorization header.
        $queryToken = $request->query('token');
        if ($queryToken) {
            try {
                $user = JWTAuth::setToken($queryToken)->authenticate();
                auth()->setUser($user);
            } catch (\Exception $e) {
                return $this->error('UNAUTHORIZED', 'Invalid or missing authentication token', 401);
            }
        }

        $user = auth()->user();
        if (! $user) {
            return $this->error('UNAUTHORIZED', 'Invalid or missing authentication token', 401);
        }

        $festivalId = $request->header('X-Festival-Id');
        if (empty($festivalId)) {
            return $this->error('TENANT_REQUIRED', 'X-Festival-Id header is required', 400);
        }

        $membership = MandalMember::where('user_id', $user->id)
            ->where('is_active', true)
            ->whereIn('mandal_id', \App\Models\Festival::where('id', $festivalId)->pluck('mandal_id'))
            ->exists();

        if (! $membership) {
            return $this->error('FORBIDDEN', 'You are not a member of this festival', 403);
        }

        $lastEventId = $request->header('Last-Event-ID');

        $response = new StreamedResponse(function () use ($festivalId, $lastEventId) {
            while (true) {
                // Heartbeat every 15 seconds to keep the connection alive
                $heartbeatData = json_encode([
                    'timestamp' => now()->toIso8601String(),
                    'festivalId' => $festivalId,
                ]);

                echo "id: heartbeat_" . now()->timestamp . "\n";
                echo "event: heartbeat\n";
                echo "data: {$heartbeatData}\n\n";

                ob_flush();
                flush();

                if (connection_aborted()) {
                    break;
                }

                // TODO: Fetch new events from a real-time queue; replay from lastEventId if provided.
                // For now we only send the heartbeat as instructed.

                sleep(15);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }
}
