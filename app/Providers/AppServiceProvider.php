<?php

namespace App\Providers;

use App\Contracts\SquareCustomerGateway;
use App\Services\Square\NullSquareCustomerGateway;
use App\Services\Square\SdkSquareCustomerGateway;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Spec Sections 4.1 and 9: with a Square access token
        // configured, signup provisions through the real SDK gateway;
        // without one it uses the null gateway, which is the same
        // pending-provisioning end state. A token is the whole switch,
        // so no environment can half-configure provisioning.
        $this->app->bind(SquareCustomerGateway::class, function ($app) {
            return empty(config('services.square.access_token'))
                ? $app->make(NullSquareCustomerGateway::class)
                : $app->make(SdkSquareCustomerGateway::class);
        });
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
