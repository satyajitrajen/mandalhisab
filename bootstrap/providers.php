<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\JwtAuthServiceProvider::class,
    Tymon\JWTAuth\Providers\LaravelServiceProvider::class,
    Barryvdh\DomPDF\ServiceProvider::class,
    Kreait\Laravel\Firebase\ServiceProvider::class,
];
