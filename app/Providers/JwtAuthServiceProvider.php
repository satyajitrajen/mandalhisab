<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Tymon\JWTAuth\JWTGuard;

class JwtAuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        \Auth::extend('jwt', function ($app, $name, array $config) {
            $guard = new JWTGuard(
                $app['tymon.jwt'],
                $app['auth']->createUserProvider($config['provider']),
                $app['request']
            );

            $app->rebinding('request', function ($app, $request) use ($guard) {
                $guard->setRequest($request);
            });

            return $guard;
        });
    }
}
