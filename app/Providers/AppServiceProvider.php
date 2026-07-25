<?php

namespace App\Providers;

use App\Support\UrlGuard;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bound rather than constructed inline so the test suite can swap in
        // a guard with a stubbed resolver. Everything that reaches the network
        // goes through this one instance.
        $this->app->bind(UrlGuard::class, static fn (): UrlGuard => UrlGuard::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
