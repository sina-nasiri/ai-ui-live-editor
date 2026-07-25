<?php

namespace Tests;

use App\Support\UrlGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stub DNS so the suite runs offline and cannot go red because a
        // resolver was slow. Hostnames map to a public address; literal IPs
        // short-circuit inside the guard, so every SSRF test still exercises
        // the real range checks against the real address it was given.
        $this->app->bind(UrlGuard::class, static fn (): UrlGuard => new UrlGuard(
            (bool) config('editor.proxy.allow_private_networks', false),
            (array) config('editor.proxy.allowed_hosts', []),
            static fn (string $host): array => ['93.184.216.34'],
        ));
    }

    /**
     * Use the real resolver for a test that genuinely needs to exercise it.
     */
    protected function withRealDns(): void
    {
        $this->app->bind(UrlGuard::class, static fn (): UrlGuard => UrlGuard::fromConfig());
    }
}
