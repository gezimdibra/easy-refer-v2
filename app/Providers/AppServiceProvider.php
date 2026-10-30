<?php

namespace App\Providers;

use App\Contracts\MarketingSmsGateway;
use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Contracts\SquareCustomerGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Services\Square\NullSquareCustomerGateway;
use App\Services\Square\NullSquarePaymentsGateway;
use App\Services\Square\SdkSquareCustomerGateway;
use App\Services\Square\SdkSquarePaymentsGateway;
use App\Services\Twilio\NullMarketingSmsGateway;
use App\Services\Twilio\NullPhoneLookupGateway;
use App\Services\Twilio\NullPhoneVerificationGateway;
use App\Services\Twilio\NullSmsGateway;
use App\Services\Twilio\TwilioMarketingSmsGateway;
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

        // Spec Section 9, Phase 4: the payments gateway follows
        // the same switch. Record-only mode is a separate gate
        // (billing.charges_enabled); this binding only decides
        // whether a configured environment can move money at all.
        $this->app->bind(SquarePaymentsGateway::class, function ($app) {
            return empty(config('services.square.access_token'))
                ? $app->make(NullSquarePaymentsGateway::class)
                : $app->make(SdkSquarePaymentsGateway::class);
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

        // Spec Section 10.3, the parallel switch for the
        // marketing pool: the real gateway binds only when the
        // account key set AND the marketing messaging service are
        // configured; otherwise the null gateway keeps campaign
        // surfaces closed-but-graceful and the pools can never be
        // crossed.
        $this->app->bind(MarketingSmsGateway::class, function ($app) {
            return $this->twilioConfigured() && ! empty(config('services.twilio.marketing_messaging_service_sid'))
                ? $app->make(TwilioMarketingSmsGateway::class)
                : $app->make(NullMarketingSmsGateway::class);
        });

        $this->app->bind(PhoneLookupGateway::class, function ($app) {
            return $this->twilioConfigured()
                ? $app->make(TwilioPhoneLookupGateway::class)
                : $app->make(NullPhoneLookupGateway::class);
        });

        // G6 demo Phase 1 (Amendment 63): the demo verification
        // gateway is bound under its OWN class name, scoped so
        // one request shares one pending-code store. It is never
        // bound to the PhoneVerificationGateway contract, so no
        // non-demo path can resolve it and no demo path can
        // resolve the Twilio gateway through it.
        $this->app->scoped(\App\Services\Demo\DemoVerificationGateway::class);
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
        // B7 M-1: a controller that already counted the held
        // collections this request (the dashboard) hands the
        // count over; every other page counts as before.
        \Illuminate\Support\Facades\View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            $count = 0;

            $handedCount = request()->attributes->get('review_queue_count');

            if (is_int($handedCount)) {
                $count = $handedCount;
            } elseif ($user !== null && $user->business_id !== null) {
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

        // The Action Center badge in the dashboard shell
        // (RK-01): the count of open items for the signed-in
        // business. Zero renders no badge at all (silence =
        // healthy, Amendment 22 AC-02).
        \Illuminate\Support\Facades\View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            $count = 0;

            if ($user !== null && $user->business !== null) {
                $checklist = request()->attributes->get('action_center_checklist');
                // B7: forward any of this request's already
                // fetched Action Center inputs the controller
                // handed over (held collections, dismissal map,
                // redeemed-referral fact), so the badge's read
                // model does not fetch any of them a second time.
                $shared = [];
                $heldReferrals = request()->attributes->get('action_center_held_referrals');
                if ($heldReferrals instanceof \Illuminate\Support\Collection) {
                    $shared['held_referrals'] = $heldReferrals;
                }
                $heldRewards = request()->attributes->get('action_center_held_rewards');
                if ($heldRewards instanceof \Illuminate\Support\Collection) {
                    $shared['held_rewards'] = $heldRewards;
                }
                $dismissals = request()->attributes->get('action_center_dismissals');
                if ($dismissals instanceof \Illuminate\Support\Collection) {
                    $shared['dismissals'] = $dismissals;
                }
                $hasRedeemed = request()->attributes->get('has_redeemed_referral');
                if (is_bool($hasRedeemed)) {
                    $shared['has_redeemed_referral'] = $hasRedeemed;
                }
                $count = app(\App\Services\Owner\ActionCenter::class)
                    ->openCount($user->business, $user, is_array($checklist) ? $checklist : null, $shared === [] ? null : $shared);
            }

            $view->with('actionCenterCount', $count);
        });
    }
}
