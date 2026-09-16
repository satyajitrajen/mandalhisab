<?php

/**
 * No-op stand-ins for require-dev providers.
 *
 * Production is installed with --no-dev, but deploys often copy
 * bootstrap/cache/packages.php from a local machine that still lists
 * Collision / Ignition / Sail. Without these stubs every request 500s.
 */

namespace NunoMaduro\Collision\Adapters\Laravel {
    if (! class_exists(CollisionServiceProvider::class, false)) {
        class CollisionServiceProvider extends \Illuminate\Support\ServiceProvider
        {
            public function register(): void {}

            public function boot(): void {}
        }
    }
}

namespace Laravel\Sail {
    if (! class_exists(SailServiceProvider::class, false)) {
        class SailServiceProvider extends \Illuminate\Support\ServiceProvider
        {
            public function register(): void {}

            public function boot(): void {}
        }
    }
}

namespace Spatie\LaravelIgnition {
    if (! class_exists(IgnitionServiceProvider::class, false)) {
        class IgnitionServiceProvider extends \Illuminate\Support\ServiceProvider
        {
            public function register(): void {}

            public function boot(): void {}
        }
    }
}

namespace Spatie\LaravelIgnition\Facades {
    if (! class_exists(Flare::class, false)) {
        class Flare extends \Illuminate\Support\Facades\Facade
        {
            protected static function getFacadeAccessor(): string
            {
                return 'flare';
            }
        }
    }
}
