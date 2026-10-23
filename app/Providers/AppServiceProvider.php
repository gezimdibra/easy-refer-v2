<?php

namespace App\Providers;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Contracts\SquareCustomerGateway;
use App\Services\Square\NullSquareCustomerGateway;
use App\Services\Square\SdkSquareCustomerGateway;
use App\Services\Twilio\NullPhoneLookupGateway;
use App\Services\Twilio\NullPhoneVerificationGateway;
use App\Services\Twilio\NullSmsGateway;
use App\Services\Twilio\TwilioPhoneLookupGateway;
use App\Services\Twilio\TwilioPhoneVerificationGateway;
use App\Services\Twilio\TwilioSmsGateway;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The application router is installed in bootstrap/app.php,
        // before any router resolution can happen; see the note
        // there for why a provider is too late for the kernel the
        // production entry builds. This provider must not rebind
        // or extend the router: a second instance here is exactly
        // the split the bootstrap wiring exists to prevent.

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

        // Spec Section 10, same switch discipline as Square: the
        // Twilio gateways bind real only when the full key set is
        // configured; otherwise the null gateways keep every flow
        // closed-but-graceful (verification unavailable, sends
        // deferred to their retry paths, lookup signals neutral).
        $this->app->bind(PhoneVerificationGateway::class, function ($app) {
            return $this->twilioConfigured() && ! empty(config('services.twilio.verify_service_sid'))
                ? $app->make(TwilioPhoneVerificationGateway::class)
                : $app->make(NullPhoneVerificationGateway::class);
        });

        $this->app->bind(SmsGateway::class, function ($app) {
            return $this->twilioConfigured() && ! empty(config('services.twilio.messaging_service_sid'))
                ? $app->make(TwilioSmsGateway::class)
                : $app->make(NullSmsGateway::class);
        });

        $this->app->bind(PhoneLookupGateway::class, function ($app) {
            return $this->twilioConfigured()
                ? $app->make(TwilioPhoneLookupGateway::class)
                : $app->make(NullPhoneLookupGateway::class);
        });
    }

    /**
     * Whether the account-level Twilio key set is fully configured.
     */
    private function twilioConfigured(): bool
    {
        return ! empty(config('services.twilio.account_sid'))
            && ! empty(config('services.twilio.api_key_sid'))
            && ! empty(config('services.twilio.api_key_secret'));
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

        // The review queue badge in the dashboard shell: held
        // referrals plus held rewards for the signed-in business.
        \Illuminate\Support\Facades\View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            $count = 0;

            if ($user !== null && $user->business_id !== null) {
                $count = \App\Models\Referral::query()
                    ->where('business_id', $user->business_id)
                    ->where('hold', true)
                    ->count()
                    + \App\Models\Reward::query()
                        ->where('business_id', $user->business_id)
                        ->where('hold', true)
                        ->count();
            }

            $view->with('reviewQueueCount', $count);
        });
    }
}
