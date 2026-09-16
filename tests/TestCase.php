<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Persistent cache drivers (file, redis) keep rate-limit counters
        // alive across tests, unlike the array driver. Flush so the suite is
        // deterministic on any CACHE_STORE.
        Cache::flush();
    }
}
