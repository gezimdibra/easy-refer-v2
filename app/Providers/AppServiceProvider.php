<?php

namespace App\Providers;

use App\Contracts\SquareCustomerGateway;
use App\Services\Square\NullSquareCustomerGateway;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Phase 1 binds the null gateway: signup completes without a Square
        // customer until Phase 4 binds the SDK gateway (spec Sections 4.1
        // and 9). The contract keeps the signup flow identical either way.
        $this->app->bind(SquareCustomerGateway::class, NullSquareCustomerGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Spec Section 11.1: APP_URL is the single source of truth for the
        // mount point. Every generated URL derives from it, so the same
        // build serves from the staging folder or the domain root with no
        // code change, only the config flip listed in spec Section 11.2.
        URL::forceRootUrl((string) config('app.url'));
    }
}
