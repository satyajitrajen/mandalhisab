<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use App\Traits\ApiResponse;

class AuthenticateJwt
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        try {
            // SSE EventSource cannot set an Authorization header, so also accept
            // the JWT via ?token= query param.
            $queryToken = $request->query('token');
            $user = $queryToken
                ? JWTAuth::setToken($queryToken)->authenticate()
                : JWTAuth::parseToken()->authenticate();

            if (! $user) {
                return $this->error('UNAUTHORIZED', 'User not found', 401);
            }

            // Reject soft-deleted accounts immediately
            if ($user->deleted_at !== null) {
                return $this->error('UNAUTHORIZED', 'Account has been deleted', 401);
            }

            auth()->setUser($user);
        } catch (TokenExpiredException) {
            return $this->error('TOKEN_EXPIRED', 'Token has expired', 401);
        } catch (JWTException) {
            return $this->error('UNAUTHORIZED', 'Missing or invalid token', 401);
        }

        return $next($request);
    }
}
