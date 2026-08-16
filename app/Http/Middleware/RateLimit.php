<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\RateLimiter as LaravelRateLimiter;
use App\Traits\ApiResponse;

class RateLimit
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->path();
        $ip = $request->ip();
        $user = auth()->user();
        $userKey = $user ? 'user:' . $user->id : 'ip:' . $ip;

        // Auth endpoints: 5/min per IP
        if (str_contains($path, 'auth/login') || str_contains($path, 'auth/register')) {
            $key = 'auth:' . $ip;
            if (LaravelRateLimiter::tooManyAttempts($key, 5)) {
                return $this->error('RATE_LIMITED', 'Too many attempts. Please try again later.', 429);
            }
            LaravelRateLimiter::hit($key, 60);
            return $next($request);
        }

        // Read APIs: 120/min per user
        if (in_array($request->method(), ['GET', 'HEAD'])) {
            $key = 'read:' . $userKey;
            if (LaravelRateLimiter::tooManyAttempts($key, 120)) {
                return $this->error('RATE_LIMITED', 'Rate limit exceeded for read operations', 429);
            }
            LaravelRateLimiter::hit($key, 60);
            return $next($request);
        }

        // Sync batch: 10/min per user
        if (str_contains($path, 'sync/batch')) {
            $key = 'sync:' . $userKey;
            if (LaravelRateLimiter::tooManyAttempts($key, 10)) {
                return $this->error('RATE_LIMITED', 'Sync batch rate limit exceeded', 429);
            }
            LaravelRateLimiter::hit($key, 60);
            return $next($request);
        }

        // Write APIs: 60/min per user
        $key = 'write:' . $userKey;
        if (LaravelRateLimiter::tooManyAttempts($key, 60)) {
            return $this->error('RATE_LIMITED', 'Rate limit exceeded for write operations', 429);
        }
        LaravelRateLimiter::hit($key, 60);

        return $next($request);
    }
}
