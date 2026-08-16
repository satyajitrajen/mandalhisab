<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\TenantScope;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\IdempotencyGuard;
use App\Http\Middleware\RateLimit;
use App\Http\Middleware\HisabLocked;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'jwt.auth' => AuthenticateJwt::class,
            'tenant.scope' => TenantScope::class,
            'role' => RequireRole::class,
            'idempotency' => IdempotencyGuard::class,
            'rate.limit' => RateLimit::class,
            'hisab.locked' => HisabLocked::class,
        ]);

        $middleware->api(prepend: [
            // Ensure JSON requests are handled properly
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Handle validation errors (422)
        $exceptions->renderable(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->is('api/*')) {
                $errors = collect($e->errors())->flatten()->map(function ($message, $index) use ($e) {
                    $field = array_keys($e->errors())[$index] ?? 'field';
                    return [
                        'field' => $field,
                        'issue' => $message,
                    ];
                })->values()->toArray();

                return response()->json([
                    'success' => false,
                    'statusCode' => 422,
                    'error' => [
                        'code' => 'VALIDATION_FAILED',
                        'message' => $e->getMessage(),
                        'details' => $errors,
                    ],
                    'timestamp' => now()->toIso8601String(),
                ], 422);
            }
        });

        // Generic API exception handler
        $exceptions->renderable(function (\Throwable $e, $request) {
            if ($request->is('api/*')) {
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    return null; // Let the dedicated handler above process it
                }
                $code = class_basename($e);
                $message = $e->getMessage() ?: 'Internal server error';

                return response()->json([
                    'success' => false,
                    'statusCode' => $status,
                    'error' => [
                        'code' => $code,
                        'message' => $message,
                        'details' => [],
                    ],
                    'timestamp' => now()->toIso8601String(),
                ], $status);
            }
        });
    })->create();
