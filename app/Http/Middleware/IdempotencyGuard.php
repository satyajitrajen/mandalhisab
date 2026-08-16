<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\IdempotencyRecord;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Hash;

class IdempotencyGuard
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key || ! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $user = auth()->user();
        if (! $user) {
            return $next($request);
        }

        $route = $request->route()->getName() ?? $request->path();
        $requestHash = md5($request->getContent());

        $existing = IdempotencyRecord::where('idempotency_key', $key)
            ->where('user_id', $user->id)
            ->where('route', $route)
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            if ($existing->request_hash !== $requestHash) {
                return $this->error('CONFLICT', 'Idempotency key reused with different request body', 409);
            }

            $decoded = json_decode($existing->response_body, true);
            $statusCode = is_array($decoded) && isset($decoded['statusCode']) && is_int($decoded['statusCode'])
                ? $decoded['statusCode']
                : 200;

            return response()->json($decoded, $statusCode);
        }

        $response = $next($request);

        if ($response->isSuccessful() || $response->isRedirection()) {
            IdempotencyRecord::create([
                'idempotency_key' => $key,
                'user_id' => $user->id,
                'route' => $route,
                'request_hash' => $requestHash,
                'response_body' => $response->getContent(),
                'expires_at' => now()->addHours(24),
            ]);
        }

        return $response;
    }
}
