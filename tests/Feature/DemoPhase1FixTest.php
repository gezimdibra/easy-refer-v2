<?php

namespace Tests\Feature;

use App\Contracts\MarketingSmsGateway;
use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Mail\BillingForecastMail;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\DemoSession;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Models\User;
use App\Services\Billing\ForecastRemindersService;
use App\Services\Billing\MonthlyCharger;
use App\Services\Billing\SettlementService;
use App\Services\Billing\VisitValuation;
use App\Services\Demo\DemoService;
use App\Support\BillingForecast;
use App\Support\CanonicalEmail;
use App\Support\OpaqueToken;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * A-18 two-key BLOCK discharge pins (demo/phase1-fix): one or
 * more pins per condition in
 * hidden_files/night-loop/demo-phase1-twokey-review.md.
 * Finding numbers below are that verdict's.
 */
class DemoPhase1FixTest extends TestCase
{
    use RefreshDatabase;

    private Business $juniper;

    private string $phone = '2125550147';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();

        $this->juniper = app(DemoService::class)->ensureBusiness();
    }

    // -- persona drivers (the DemoPhase1Test shapes) ---------------

    private function startDemo(): void
    {
        $response = $this->post(route('demo.start'), ['entry_point' => 'block']);
        $response->assertRedirect(route('demo.claim'));
        $this->carryCookiesFrom($response);
    }

    private function submitAndVerify(): void
    {
        $this->post(route('demo.claim.next'))->assertRedirect(route('demo.form'));
        $this->get(route('demo.form'))->assertOk();
        $this->post(route('demo.form.submit'), [
            'first_name' => 'Pat', 'last_name' => 'Reyes',
            'phone' => $this->phone, 'consent' => '1',
        ])->assertRedirect(route('demo.verify'));
        $page = $this->get(route('demo.verify'));
        preg_match('/demo-code-value">(\d{6})</', $page->getContent(), $m);
        $this->assertNotEmpty($m);
        $this->post(route('demo.verify.check'), ['code' => $m[1]])
            ->assertRedirect(route('demo.reward'));
    }

    private function completeLoop(): void
    {
        $this->startDemo();
        $this->submitAndVerify();
        $reward = $this->get(route('demo.reward'));
        $reward->assertOk();
        preg_match('#/demo/counter/([A-Za-z0-9\-_]+)#', $reward->getContent(), $m);
        $this->assertNotEmpty($m);
        $counter = $this->get(route('demo.counter', ['token' => $m[1]]));
        $counter->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $counter->getContent(), $n);
        $this->assertNotEmpty($n);
        $this->post(route('demo.counter.confirm', ['token' => $m[1]]), ['nonce' => $n[1]])
            ->assertRedirect(route('demo.counter', ['token' => $m[1]]));
    }

    private function mismatchReferral(Business $business, string $source, array $extra = []): Referral
    {
        $token = OpaqueToken::generate();
        $customer = World::customer('+1212555'.str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT));

        return Referral::create(array_merge([
            'business_id' => $business->id,
            'source' => $source,
            'referrer_membership_id' => null,
            'referred_customer_id' => $customer->id,
            'redeem_token_hash' => OpaqueToken::hash($token),
            'redeem_token_encrypted' => $token,
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => now(),
            'expires_at' => now()->addDays(30),
        ], $extra));
    }

    // -- Finding 1: carryover phone dies with the demo session -----

    public function test_f1_register_prefill_reads_the_live_demo_session_and_is_gone_after_expiry(): void
    {
        $this->completeLoop();

        $this->post(route('demo.exit'), ['from' => 'coda'])
            ->assertRedirect(route('register'));

        // While the demo session lives, the register page
        // offers the typed phone back.
        $live = $this->get(route('register'));
        $live->assertOk();
        $live->assertSee('value="+1 (212) 555-0147"', false);

        // After the demo session expires, a GET on the register
        // page renders NO prefill. (The A-12 pin this replaces
        // never performed this GET.)
        $this->travel(16)->minutes();
        $expired = $this->get(route('register'));
        $expired->assertOk();
        $expired->assertDontSee('+1 (212) 555-0147');
        $this->assertSame(0, DemoSession::query()->count());
    }

    public function test_f1_a_stale_main_session_copy_is_never_rendered_as_prefill(): void
    {
        // A copy an older build left in the main session, with
        // no live demo session behind it, is dropped unread.
        $page = $this->withSession(['demo.carryover_phone' => '+1 (212) 555-0147'])
            ->get(route('register'));
        $page->assertOk();
        $page->assertDontSee('+1 (212) 555-0147');
    }

    // -- Finding 2: expiry means deletion, on a short cadence -------

    public function test_f2_the_minutely_sweep_deletes_an_expired_session_and_all_its_derived_rows(): void
    {
        $this->completeLoop();
        $this->assertSame(1, Referral::query()->where('business_id', $this->juniper->id)->count());
        $this->assertSame(1, Customer::query()->where('phone_e164', 'like', 'demo:%')->count());

        $this->travel(16)->minutes();

        // Nobody returns to the demo. The sweep alone must do
        // the whole teardown.
        $this->artisan('demo:sweep-expired')->assertSuccessful();

        $this->assertSame(0, DemoSession::query()->count());
        $this->assertSame(0, Referral::query()->where('business_id', $this->juniper->id)->count());
        $this->assertSame(0, BillingEvent::query()->where('business_id', $this->juniper->id)->count());
        $this->assertSame(0, Membership::query()->where('business_id', $this->juniper->id)->count());
        $this->assertSame(0, Customer::query()->where('phone_e164', 'like', 'demo:%')->count());
        $this->assertSame(0, ConsentEvent::query()->where('business_id', $this->juniper->id)->count());
        $this->assertSame(0, ReferralEvent::query()->where('business_id', $this->juniper->id)->count());

        // A live session is untouched by the same sweep.
        $this->startDemo();
        $this->artisan('demo:sweep-expired')->assertSuccessful();
        $this->assertSame(1, DemoSession::query()->count());
    }

    public function test_f2_expiry_on_sight_tears_down_derived_rows_before_the_timeout_screen_renders(): void
    {
        $this->completeLoop();
        $this->travel(16)->minutes();

        $this->get(route('demo.coda'))->assertSee('This demo timed out. Nothing from it was kept.');

        // At the moment that screen rendered, the claim rows
        // were already gone, not merely scheduled for deletion.
        $this->assertSame(0, DemoSession::query()->count());
        $this->assertSame(0, Referral::query()->where('business_id', $this->juniper->id)->count());
        $this->assertSame(0, Customer::query()->where('phone_e164', 'like', 'demo:%')->count());
        $this->assertSame(0, Membership::query()->where('business_id', $this->juniper->id)->count());
    }

    public function test_f2_the_sweep_is_scheduled_on_a_short_cadence(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $sweep = $events->first(fn ($event) => str_contains((string) $event->command, 'demo:sweep-expired'));

        $this->assertNotNull($sweep, 'The demo expiry sweep is scheduled.');
        $this->assertSame('* * * * *', $sweep->expression, 'The sweep runs every minute, so deletion lands within a few minutes of expiry.');
    }

    // -- Finding 3: both legs, per surface ---------------------------

    public function test_f3_admin_visits_and_holds_exclude_both_mismatch_shapes_and_count_real_rows(): void
    {
        $real = World::business();
        $referrerCustomer = World::customer('+12125550111');
        [$referrerMembership] = World::membership($real, $referrerCustomer);
        $friend = World::customer('+12125550222');
        [$realReferral] = World::referral($referrerMembership, $friend);
        $realReferral->forceFill(['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(), 'hold' => true])->save();

        // Mismatch A: unstamped referral on the demo business.
        $this->mismatchReferral($this->juniper, 'referral', ['hold' => true]);
        // Mismatch B: demo-stamped referral on a real business.
        $this->mismatchReferral($real, 'demo', ['hold' => true]);

        $admin = $this->adminWithTwoFactor();
        $home = $this->actingAs($admin, 'admin')
            ->withSession(['admin_auth.two_factor_passed' => true])
            ->get(route('admin.home'));
        $home->assertOk();
        $home->assertViewHas('visitsToday', 1);
        $home->assertViewHas('openHolds', 1);
        $home->assertViewHas('businessTotal', 1);
    }

    public function test_f3_charger_and_settlement_walk_each_carry_both_legs(): void
    {
        // Flag leg in the walk: a billable-stamped event on the
        // demo business settles as demo at $0 and never charges.
        $demoEvent = BillingEvent::create([
            'business_id' => $this->juniper->id, 'referral_id' => $this->mismatchReferral($this->juniper, 'referral')->id,
            'amount_cents' => 200, 'coverage' => 'billable',
        ]);
        $settlement = (new VisitValuation)->settle($this->juniper);
        $this->assertSame(0, $settlement['fees_cents']);
        $this->assertSame(0, $settlement['verified_visits']);
        $this->assertSame('demo', $settlement['settled'][$demoEvent->id]['coverage']);
        $this->assertNull(app(MonthlyCharger::class)->charge($this->juniper));
        $this->assertSame(0, BillingRecord::query()->where('business_id', $this->juniper->id)->count());

        // Coverage leg in the walk: a demo-stamped event on a
        // real business settles as demo at $0, and the charger
        // never batches it.
        $real = World::business();
        $real->forceFill(['free_visits_remaining' => 0])->save();
        $realEvent = BillingEvent::create([
            'business_id' => $real->id, 'referral_id' => $this->mismatchReferral($real, 'demo')->id,
            'amount_cents' => 200, 'coverage' => 'demo',
        ]);
        $realSettlement = (new VisitValuation)->settle($real);
        $this->assertSame('demo', $realSettlement['settled'][$realEvent->id]['coverage']);
        $this->assertSame(0, $realSettlement['fees_cents']);
        $this->assertNull(app(MonthlyCharger::class)->charge($real));
        $this->assertSame(0, BillingRecord::query()->where('business_id', $real->id)->count());
        $this->assertNull($realEvent->fresh()->billing_record_id);
    }

    public function test_f3_forecast_surfaces_carry_both_legs_and_the_reminders_sweep_never_selects_the_demo_business(): void
    {
        BillingEvent::create([
            'business_id' => $this->juniper->id, 'referral_id' => $this->mismatchReferral($this->juniper, 'referral')->id,
            'amount_cents' => 500, 'coverage' => 'billable',
        ]);

        // The forecast read itself: zeroed for the demo
        // business even with a billable-stamped event on it.
        $forecast = app(SettlementService::class)->forecastPreview($this->juniper);
        $this->assertSame(0, $forecast['total_cents']);
        $this->assertSame(0, $forecast['billable_events']);
        $this->assertFalse(BillingForecast::chargeIsExpected($this->juniper));
        $this->assertSame(0, BillingForecast::gatedPreview($this->juniper)['total_cents']);
        $this->assertNull(BillingForecast::line($this->juniper));
        $this->assertNull(BillingForecast::emailBody($this->juniper));
        $this->assertNull(BillingForecast::emailFacts($this->juniper));

        // The sweep: give the demo business the two things that
        // used to be its only protection removed, an owner and
        // a default card, inside the forecast window. It still
        // sends nothing, while a real business in the same
        // state does.
        User::create([
            'business_id' => $this->juniper->id, 'role' => 'owner',
            'name' => 'Demo Owner', 'email' => 'demo-owner@example.test',
            'canonical_email' => CanonicalEmail::of('demo-owner@example.test'),
            'phone_e164' => '+12125550998', 'password' => Hash::make('WorldPassword01!'),
            'email_verified_at' => now(),
        ]);
        $this->card($this->juniper);

        $real = World::business();
        $real->forceFill(['free_visits_remaining' => 0])->save();
        BillingEvent::create([
            'business_id' => $real->id, 'referral_id' => $this->mismatchReferral($real, 'referral')->id,
            'amount_cents' => 200, 'coverage' => 'billable',
        ]);
        $this->card($real);

        $this->travelTo(now()->endOfMonth()->subDays(1)->setTime(12, 0));

        $summary = app(ForecastRemindersService::class)->run();
        $this->assertSame(1, $summary['forecast'], 'Only the real business is forecast-emailed.');
        Mail::assertSent(BillingForecastMail::class, 1);
    }

    public function test_f3_owner_lists_exclude_a_demo_stamped_row_on_their_own_business(): void
    {
        $real = World::business();
        $owner = World::owner($real);
        $referrerCustomer = World::customer('+12125550111');
        [$referrerMembership] = World::membership($real, $referrerCustomer);
        $friend = World::customer('+12125550222');
        [$normal] = World::referral($referrerMembership, $friend);
        $normal->forceFill(['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()])->save();
        $this->mismatchReferral($real, 'demo');

        $list = $this->actingAs($owner)->get(route('referrals.index'));
        $list->assertOk();
        $list->assertViewHas('counts', fn (array $counts) => $counts['all'] === 1 && $counts['redeemed'] === 1);

        $dashboard = $this->actingAs($owner)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertViewHas('counters', fn (array $counters) => $counters['completed_total'] === 1);
    }

    // -- Finding 4: one guard path -----------------------------------

    public function test_f4_a_stamp_mismatch_row_is_refused_on_the_qr_reward_and_counter_surfaces_alike(): void
    {
        $this->startDemo();
        $this->submitAndVerify();

        $referral = Referral::query()->where('business_id', $this->juniper->id)->firstOrFail();
        $token = (string) $referral->redeem_token_encrypted;

        // Control: with both stamps intact the QR serves.
        $this->get(route('demo.qr', ['token' => $token]))->assertOk();

        // Break the source stamp. Every token surface must now
        // refuse the row, not only the counter.
        $referral->forceFill(['source' => 'referral'])->save();

        $this->get(route('demo.qr', ['token' => $token]))->assertStatus(404);
        $this->get(route('demo.reward'))->assertRedirect(route('demo.form'));
        $this->get(route('demo.counter', ['token' => $token]))
            ->assertSee('This code is not active');
    }

    // -- Findings 5, 6, 7: copy and the DEMO device -------------------

    public function test_f5_f6_f7_copy_and_device_pins(): void
    {
        // F6: the overclaims are gone from both surfaces.
        $home = $this->get(route('home'));
        $home->assertDontSee('exact screens');
        $home->assertDontSee('Everything it does is real');
        $this->get(route('demo.show'))->assertDontSee('exact screens');

        $this->startDemo();
        $this->submitAndVerify();

        // F5: no friend-facing wait or unlock copy on the
        // reward screen; the timing truth lives on the staff
        // (counter) screen, in staff terms.
        $reward = $this->get(route('demo.reward'));
        $reward->assertOk();
        $reward->assertDontSee('unlocks');
        $reward->assertDontSee('short wait');
        $reward->assertSee('Yours is ready right away.');

        $counter = $this->get(route('demo.counter', ['token' => (string) Referral::query()->where('business_id', $this->juniper->id)->firstOrFail()->redeem_token_encrypted]));
        $counter->assertOk();
        $counter->assertSee('25 minutes');
        $counter->assertSee('names the time the reward opens');

        // F7: the business name renders with its Demo shop
        // chip in body copy, not naked. A fresh session walks
        // the claim and form screens (a claimed session's form
        // redirects to its reward by design).
        $nameWithChip = '<span>Juniper Café</span><span class="demo-chip-outline">Demo shop</span>';
        $this->startDemo();
        $this->get(route('demo.claim'))->assertSee($nameWithChip, false);
        $this->post(route('demo.claim.next'))->assertRedirect(route('demo.form'));
        $this->get(route('demo.form'))->assertSee($nameWithChip, false);
    }

    public function test_f7_the_resting_screen_carries_the_demo_device(): void
    {
        config(['demo.guards.global_starts_per_day' => 0]);

        $resting = $this->get(route('demo.show'));
        $resting->assertOk();
        $resting->assertSee('The demo is resting right now.');
        $resting->assertSee('DEMO');
        $resting->assertSee('made-up shop');
        $resting->assertSee('Demo shop');
    }

    // -- helpers -------------------------------------------------------

    private function card(Business $business): PaymentMethod
    {
        return PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_fix_'.$business->id,
            'card_brand' => 'VISA',
            'last4' => '5858',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_fix_'.$business->id,
        ]);
    }

    private function adminWithTwoFactor(): User
    {
        $admin = User::create([
            'business_id' => null,
            'role' => 'platform_admin',
            'name' => 'Platform Admin',
            'email' => 'fix-admin@example.test',
            'canonical_email' => CanonicalEmail::of('fix-admin@example.test'),
            'phone_e164' => '+12125550999',
            'password' => Hash::make('AdminPassword01!'),
            'email_verified_at' => now(),
        ]);

        $service = app(\App\Services\Auth\TwoFactorService::class);
        $service->startEnrollment($admin, 'AdminPassword01!');
        $admin = $admin->fresh();
        $service->confirmEnrollment($admin, \App\Support\Totp::code((string) $admin->two_factor_secret), 'AdminPassword01!');

        return $admin->fresh();
    }
}
