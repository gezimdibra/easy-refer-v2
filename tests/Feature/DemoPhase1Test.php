<?php

namespace Tests\Feature;

use App\Contracts\MarketingSmsGateway;
use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Customer;
use App\Models\DemoAttribution;
use App\Models\DemoEvent;
use App\Models\DemoReseedLog;
use App\Models\DemoSession;
use App\Models\Referral;
use App\Services\Billing\MonthlyCharger;
use App\Services\Billing\VisitValuation;
use App\Services\Demo\DemoService;
use App\Support\CanonicalEmail;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * G6 DEMO PHASE 1 acceptance (Amendment 63; design pass
 * Section 17, items A-1 to A-16 as far as the suite can prove
 * them; A-17's geometry captures and A-18's two-key review are
 * gate artifacts recorded in the build report). Every test
 * runs the real routes against the seeded Juniper Café with
 * spy gateways bound, so the zero-real-SMS proof (A-6) is an
 * assertion over the full persona runs, not an inspection.
 */
class DemoPhase1Test extends TestCase
{
    use RefreshDatabase;

    private FakePhoneVerificationGateway $verify;

    private FakeSmsGateway $sms;

    private FakeMarketingSmsGateway $marketing;

    private Business $juniper;

    private string $phone = '2125550147';

    private string $phoneE164 = '+12125550147';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->verify = new FakePhoneVerificationGateway;
        $this->sms = new FakeSmsGateway;
        $this->marketing = new FakeMarketingSmsGateway;
        $this->app->instance(PhoneVerificationGateway::class, $this->verify);
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->app->instance(MarketingSmsGateway::class, $this->marketing);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();

        $this->juniper = app(DemoService::class)->ensureBusiness();
    }

    // -- persona drivers -------------------------------------------

    private function startDemo(string $entryPoint = 'block'): \Illuminate\Testing\TestResponse
    {
        $response = $this->post(route('demo.start'), ['entry_point' => $entryPoint]);
        $response->assertRedirect(route('demo.claim'));
        $this->carryCookiesFrom($response);

        return $response;
    }

    private function toForm(): void
    {
        $this->post(route('demo.claim.next'))->assertRedirect(route('demo.form'));
        $this->get(route('demo.form'))->assertOk();
    }

    private function submitForm(string $phone = null): void
    {
        $response = $this->post(route('demo.form.submit'), [
            'first_name' => 'Pat',
            'last_name' => 'Reyes',
            'phone' => $phone ?? $this->phone,
            'consent' => '1',
        ]);
        $response->assertRedirect(route('demo.verify'));
        $this->carryCookiesFrom($response);
    }

    private function shownCode(): string
    {
        $response = $this->get(route('demo.verify'));
        $response->assertOk();
        preg_match('/demo-code-value">(\d{6})</', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'The verify screen shows a 6-digit demo code.');

        return $m[1];
    }

    private function verifyWith(string $code): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('demo.verify.check'), ['code' => $code]);
    }

    /** @return array{0: string, 1: string, 2: \Illuminate\Testing\TestResponse} the token, the nonce, and the counter page (a second GET would invalidate the nonce, so callers assert on this one) */
    private function toCounter(): array
    {
        $reward = $this->get(route('demo.reward'));
        $reward->assertOk();
        preg_match('#/demo/counter/([A-Za-z0-9\-_]+)#', $reward->getContent(), $m);
        $this->assertNotEmpty($m, 'The reward screen links to the counter moment.');
        $token = $m[1];

        $counter = $this->get(route('demo.counter', ['token' => $token]));
        $counter->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $counter->getContent(), $n);
        $this->assertNotEmpty($n, 'The counter screen carries a redemption nonce.');

        return [$token, $n[1], $counter];
    }

    private function completeLoop(): string
    {
        $this->startDemo();
        $this->toForm();
        $this->submitForm();
        $code = $this->shownCode();
        $this->verifyWith($code)->assertRedirect(route('demo.reward'));
        [$token, $nonce] = $this->toCounter();
        $this->post(route('demo.counter.confirm', ['token' => $token]), ['nonce' => $nonce])
            ->assertRedirect(route('demo.counter', ['token' => $token]));
        $this->get(route('demo.counter', ['token' => $token]))->assertOk();
        $this->get(route('demo.coda'))->assertOk();

        return $token;
    }

    private function sessionKey(): string
    {
        return (string) DemoSession::query()->first()?->data['session_key'];
    }

    // -- A-1: one tap from the top; a view is not a start ---------

    public function test_a01_landing_view_creates_no_session_and_hero_entry_is_one_tap(): void
    {
        $home = $this->get(route('home'));
        $home->assertOk();
        $home->assertSee('Try it live');
        $home->assertSee('id="demo-hero-entry"', false);
        $home->assertSee('See it work');
        $home->assertSee('The shop in the demo, Juniper Café, is made up.');
        $home->assertDontSee('exact screens');
        $home->assertDontSee('Everything it does is real');

        $this->assertSame(0, DemoSession::query()->count());
        $this->assertSame(0, DemoEvent::query()->count());

        $frame = $this->get(route('demo.show'));
        $frame->assertOk();
        $frame->assertSee('Try it live');
        $this->assertSame(0, DemoSession::query()->count(), 'The frame start card creates no session.');
        $this->assertSame(0, DemoEvent::query()->count());

        $this->startDemo('hero');
        $event = DemoEvent::query()->where('event', 'demo_started')->first();
        $this->assertNotNull($event);
        $this->assertSame('hero', $event->entry_point);
    }

    // -- F-C: the hero start handshake is load-aware, not timed ---

    public function test_fc_the_start_handshake_is_load_aware_with_a_once_guard_and_no_timed_retries(): void
    {
        $home = $this->get(route('home'));
        $home->assertOk();
        $html = $home->getContent();

        // The parent records a start intent and honours it when
        // the frame signals readiness (its load event), instead
        // of firing timed posts a still-loading lazy frame can
        // never receive.
        $this->assertStringContainsString('startIntent', $html);
        $this->assertStringContainsString("frame.addEventListener('load'", $html);
        $this->assertStringContainsString("querySelectorAll('.demo-hero-entry')", $html);
        $this->assertStringNotContainsString('setTimeout(post', $html);
        $blockStart = strpos($html, '<section id="demo-block"');
        $blockEnd = strpos($html, '</section>', (int) $blockStart);
        $blockScript = substr($html, (int) $blockStart, (int) $blockEnd - (int) $blockStart);
        $this->assertStringNotContainsString('setTimeout', $blockScript, 'No timed retry remains anywhere in the demo block script.');
        $this->assertStringContainsString('er-demo-start', $blockScript);

        // The frame side carries the once-guard: however many
        // start posts arrive in one frame load, the start form
        // submits at most once.
        $frame = $this->get(route('demo.show'));
        $frame->assertOk();
        $frame->assertSee('demoStartHandled', false);
        $frame->assertSee('id="demo-start-form"', false);
    }

    public function test_fc_a_lost_start_message_costs_nothing_and_the_load_delivery_starts_exactly_once(): void
    {
        // The lost-message case, simulated at the protocol
        // level: the hero was tapped while the lazy frame had
        // not loaded, so no start post was ever delivered. The
        // landing view and the frame's start card on their own
        // create nothing (the state the old timed retries left
        // behind when all three posts were lost).
        $this->get(route('home'))->assertOk();
        $this->get(route('demo.show'))->assertOk();
        $this->assertSame(0, DemoSession::query()->count());
        $this->assertSame(0, DemoEvent::query()->count());

        // The load-aware handshake then delivers the intent
        // exactly once, when the frame is ready: one session,
        // one demo_started, stamped with the hero entry point.
        $this->startDemo('hero');
        $this->assertSame(1, DemoSession::query()->count());
        $this->assertSame(1, DemoEvent::query()->where('event', 'demo_started')->where('entry_point', 'hero')->count());
    }

    // -- A-2/A-3/A-4/A-13: the happy path, device, events ---------

    public function test_a02_happy_path_events_device_and_friction_shape(): void
    {
        $this->startDemo();
        $claim = $this->get(route('demo.claim'));
        $claim->assertOk();
        $claim->assertSee('DEMO');
        $claim->assertSee('made-up shop');
        $claim->assertSee('Demo shop');
        $claim->assertSee('Claim your reward');
        $claim->assertSee('Like it? Make it yours');

        $this->toForm();
        $form = $this->get(route('demo.form'));
        $form->assertSee("Demo: we won't text this number. We hold it only while this demo runs, and delete it within a few minutes after the demo times out.", false);
        $form->assertSee('Use an example number instead');
        $form->assertSee('DEMO');

        $this->submitForm();
        $code = $this->shownCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $this->verifyWith($code)->assertRedirect(route('demo.reward'));

        $reward = $this->get(route('demo.reward'));
        $reward->assertOk();
        $reward->assertSee('DEMO');
        $reward->assertSee('Demo shop');
        $reward->assertSee('Show this code at the counter');
        $reward->assertSee('Demo reward. Not valid at any real shop.');
        $reward->assertSee('Yours is ready right away.');
        $reward->assertDontSee('unlocks');
        $reward->assertDontSee('short wait');

        [$token, $nonce, $counter] = $this->toCounter();
        $counter->assertSee('Your staff see this on their own phone when they scan the customer\'s code.', false);
        $counter->assertSee('Mark as Redeemed');
        $counter->assertSee('Demo reward. Not valid at any real shop.');

        $this->post(route('demo.counter.confirm', ['token' => $token]), ['nonce' => $nonce])
            ->assertRedirect(route('demo.counter', ['token' => $token]));

        $done = $this->get(route('demo.counter', ['token' => $token]));
        $done->assertSee('Reward redeemed');

        $coda = $this->get(route('demo.coda'));
        $coda->assertOk();
        $coda->assertSee('A new customer just walked in.');
        $coda->assertSee('Your first 5 visits are free.');
        $coda->assertSee('Never more than $99 a month in visit charges.');
        $coda->assertSee('I bring you customers in.');
        $coda->assertSee('Set up my shop');
        $coda->assertSee('Juniper Café isn\'t a real shop, and this visit wasn\'t a real visit. It was a demo of the real Easy Refer flow.', false);

        $events = DemoEvent::query()->where('session_key', $this->sessionKey())->orderBy('id')->get();
        $sequence = $events->map(fn (DemoEvent $e) => $e->event === 'demo_step_completed' ? 'step:'.$e->step : $e->event)->all();
        $this->assertSame([
            'demo_started',
            'step:claim_viewed',
            'step:form_submitted',
            'step:verification_completed',
            'step:reward_unlocked',
            'step:counter_completed',
            'demo_completed',
            'step:coda_viewed',
        ], $sequence);

        // A-13: no event payload can carry the phone or a name:
        // the ledger's columns are closed, asserted by scanning
        // every stored value of every demo event row.
        foreach ($events as $event) {
            $blob = json_encode($event->getAttributes());
            $this->assertStringNotContainsString('2125550147', (string) $blob);
            $this->assertStringNotContainsString('Reyes', (string) $blob);
        }
    }

    // -- A-5/A-6/A-9: verification truth, zero SMS, error paths ---

    public function test_a05_verify_screen_truth_and_no_sent_claims_anywhere(): void
    {
        $this->startDemo();
        $this->toForm();
        $this->submitForm();

        $verify = $this->get(route('demo.verify'));
        $verify->assertSee("In real life, this code arrives by text. In the demo, it's right here:", false);
        $verify->assertSee('Demo code for +1 (212) 555-0147');
        $verify->assertDontSee('we sent');
        $verify->assertDontSee('we texted');
        $verify->assertDontSee('on its way');
        $verify->assertDontSee('can take a minute');

        foreach ([route('demo.claim'), route('demo.form')] as $url) {
            $page = $this->get($url);
            $page->assertDontSee('we sent');
        }
    }

    public function test_a06_zero_real_sms_across_all_paths(): void
    {
        $this->startDemo();
        $this->toForm();
        $this->submitForm();
        $code = $this->shownCode();

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->verifyWith($wrong);
        $this->post(route('demo.verify.new'));
        $code2 = $this->shownCode();
        $this->post(route('demo.verify.change'));
        $this->submitForm();
        $code3 = $this->shownCode();
        $this->verifyWith($code3)->assertRedirect(route('demo.reward'));
        [$token, $nonce] = $this->toCounter();
        $this->post(route('demo.counter.confirm', ['token' => $token]), ['nonce' => $nonce]);

        $this->assertSame([], $this->verify->starts, 'No verification was ever started with a real gateway.');
        $this->assertSame([], $this->verify->checks);
        $this->assertSame([], $this->sms->sent);
        $this->assertSame([], $this->marketing->sent);
    }

    public function test_a09_wrong_empty_exhausted_and_new_code_paths(): void
    {
        $this->startDemo();
        $this->toForm();
        $this->submitForm();
        $code = $this->shownCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        // Empty submit: the demo empty-code line.
        $this->post(route('demo.verify.check'), [])->assertRedirect(route('demo.verify'));
        $this->get(route('demo.verify'))->assertSee('Enter the 6-digit code shown above.');

        // Wrong code: the true remaining count, code stays valid.
        $this->verifyWith($wrong)->assertRedirect(route('demo.verify'));
        $this->get(route('demo.verify'))->assertSee('That code did not match. You have 4 tries left.');

        // Spend the rest of the budget: the code dies.
        $this->verifyWith($wrong);
        $this->verifyWith($wrong);
        $this->verifyWith($wrong);
        $this->verifyWith($wrong);
        $exhausted = $this->get(route('demo.verify'));
        $exhausted->assertSee('That was the last try for this code.');
        $exhausted->assertSee('Show a new code');
        $exhausted->assertSee('Start the demo over');

        // The dead code stays dead even when typed correctly.
        $this->verifyWith($code);
        $this->get(route('demo.verify'))->assertSee('That was the last try for this code.');

        // A new code: issued first, confirmed after, old one dead.
        $this->post(route('demo.verify.new'))->assertRedirect(route('demo.verify'));
        $page = $this->get(route('demo.verify'));
        $page->assertSee('A new code is shown above.');
        $newCode = $this->shownCode();
        $this->verifyWith($newCode)->assertRedirect(route('demo.reward'));
    }

    public function test_a09_change_number_keeps_the_draft_and_kills_the_code(): void
    {
        $this->startDemo();
        $this->toForm();
        $this->submitForm();
        $this->shownCode();

        $this->post(route('demo.verify.change'))->assertRedirect(route('demo.form'));
        $form = $this->get(route('demo.form'));
        $form->assertSee('value="Pat"', false);
        $form->assertSee('value="+1 (212) 555-0147"', false);

        // No pending verification survives the change.
        $this->get(route('demo.verify'))->assertRedirect(route('demo.form'));
    }

    // -- A-7: phone fate -------------------------------------------

    public function test_a07_phone_lives_only_in_the_live_session_and_dies_with_it(): void
    {
        $this->completeLoop();

        // While the session lives, the typed number exists ONLY
        // in the demo session store (as the form draft the
        // carryover leg reads, and the pending it came from was
        // already forgotten at verification). No customer row
        // carries it: the claim identity is the demo-namespaced
        // ephemeral key.
        $session = DemoSession::query()->firstOrFail();
        $this->assertStringContainsString('555-0147', (string) json_encode($session->data));
        $this->assertSame(0, Customer::query()->where('phone_e164', $this->phoneE164)->count());
        $this->assertSame(1, Customer::query()->where('phone_e164', 'like', 'demo:%')->count(), 'The claim identity is the demo-namespaced ephemeral key.');
        $eventsBlob = (string) json_encode(DemoEvent::query()->get()->map->getAttributes()->all());
        $this->assertStringNotContainsString('555-0147', $eventsBlob);
        $this->assertStringNotContainsString('555-0147', (string) json_encode(Customer::query()->get()->map->getAttributes()->all()));

        // Expiry: the record is deleted on sight at the next
        // interaction, and with it the only copy of the number.
        $this->travel(16)->minutes();
        $this->get(route('demo.coda'))->assertSee('This demo timed out. Nothing from it was kept.');
        $this->assertSame(0, DemoSession::query()->count());
    }

    // -- A-10: money hygiene ----------------------------------------

    public function test_a10_money_hygiene_by_coverage_and_by_flag(): void
    {
        $freeBefore = $this->juniper->free_visits_remaining;
        $this->completeLoop();

        $referral = Referral::query()->where('business_id', $this->juniper->id)->firstOrFail();
        $this->assertSame(Referral::STATE_REDEEMED, $referral->state);
        $this->assertSame('demo', $referral->source);
        $this->assertNull($referral->referrer_membership_id);

        $event = BillingEvent::query()->where('referral_id', $referral->id)->firstOrFail();
        $this->assertSame('demo', $event->coverage);
        $this->assertSame(0, $event->amount_cents);

        $this->assertSame($freeBefore, $this->juniper->fresh()->free_visits_remaining, 'No free slot consumed.');

        // Coverage leg: the settlement walk settles it at $0 and
        // never promotes it.
        $settlement = (new VisitValuation)->settle($this->juniper);
        $this->assertSame(0, $settlement['fees_cents']);
        $this->assertSame('demo', $settlement['settled'][$event->id]['coverage']);

        // Flag leg: the charger refuses the business outright,
        // and a charge run over the month creates nothing.
        $charger = app(MonthlyCharger::class);
        $this->assertNull($charger->charge($this->juniper));
        $this->assertSame([], $charger->chargeAllMonths($this->juniper));
        $this->assertSame(0, BillingRecord::query()->where('business_id', $this->juniper->id)->count());
        $this->assertNull($event->fresh()->billing_record_id);
    }

    // -- A-11: the catastrophic pin, both directions -----------------

    public function test_a11_demo_session_cannot_touch_a_real_token_and_real_surfaces_refuse_demo_tokens(): void
    {
        // A real business with a live pending token.
        $business = World::business();
        $referrerCustomer = World::customer('+12125550111');
        [$referrerMembership] = World::membership($business, $referrerCustomer);
        $friend = World::customer('+12125550222');
        [$realReferral, $realToken] = World::referral($referrerMembership, $friend);

        // The demo runs to its own token.
        $demoToken = $this->completeLoop();
        $demoReferral = Referral::query()->where('business_id', $this->juniper->id)->firstOrFail();
        $this->assertSame(Referral::STATE_REDEEMED, $demoReferral->state);

        // Direction 1: a fresh demo session aimed at the REAL
        // token can neither view nor redeem it.
        $this->startDemo();
        $this->get(route('demo.counter', ['token' => $realToken]))
            ->assertSee('This code is not active')
            ->assertDontSee($business->name);
        $this->post(route('demo.counter.confirm', ['token' => $realToken]), ['nonce' => 'whatever'])
            ->assertSee('This code is not active');
        $this->assertSame(Referral::STATE_PENDING, $realReferral->fresh()->state);

        // Direction 2: the demo token on the real staff surface
        // answers as an unknown token and cannot be redeemed
        // into any state there.
        $this->get(route('redeem.show', ['token' => $demoToken]))->assertStatus(404);
        $this->post(route('redeem.confirm', ['token' => $demoToken]), ['nonce' => 'whatever'])->assertStatus(404);

        // And the real customer receipt page answers with its
        // generic bad-link state, naming nothing.
        $this->get(route('refer.redeem.page', ['token' => $demoToken]))
            ->assertSee('Something is not right with that link')
            ->assertDontSee('Juniper');
    }

    public function test_a11_demo_token_redeems_only_inside_the_demo_guard(): void
    {
        $this->startDemo();
        $this->toForm();
        $this->submitForm();
        $code = $this->shownCode();
        $this->verifyWith($code)->assertRedirect(route('demo.reward'));
        [$token, $nonce] = $this->toCounter();

        // The real staff surface cannot even render it.
        $this->get(route('redeem.show', ['token' => $token]))->assertStatus(404);
        $referral = Referral::query()->where('business_id', $this->juniper->id)->firstOrFail();
        $this->assertSame(Referral::STATE_PENDING, $referral->state);
        $this->assertSame(0, BillingEvent::query()->count());

        // Inside the guard it redeems, once.
        $this->post(route('demo.counter.confirm', ['token' => $token]), ['nonce' => $nonce])
            ->assertRedirect(route('demo.counter', ['token' => $token]));
        $this->assertSame(Referral::STATE_REDEEMED, $referral->fresh()->state);

        // Re-presenting the spent token shows the already state.
        $again = $this->get(route('demo.counter', ['token' => $token]));
        $again->assertSee('Reward redeemed');
        $this->assertSame(1, BillingEvent::query()->where('referral_id', $referral->id)->count());
    }

    // -- A-12: carryover ----------------------------------------------

    public function test_a12_carryover_prefills_and_only_the_allowed_things_carry(): void
    {
        $this->completeLoop();

        $exit = $this->post(route('demo.exit'), [
            'from' => 'coda',
            'shop_name' => "Tony's Slice",
            'trade' => 'pizza',
        ]);
        $exit->assertRedirect(route('register'));
        $this->carryCookiesFrom($exit);

        $register = $this->get(route('register'));
        $register->assertOk();
        $register->assertSee('value="Tony&#039;s Slice"', false);
        $register->assertSee('value="+1 (212) 555-0147"', false);
        $register->assertSee('value="other" selected', false);

        $attribution = DemoAttribution::query()->firstOrFail();
        $this->assertSame('completed', $attribution->completion_state);
        $this->assertSame('pizza', $attribution->trade_chip);
        $this->assertTrue($attribution->demo_completed);
        // The attribution record holds no phone and no shop name.
        $blob = json_encode($attribution->getAttributes());
        $this->assertStringNotContainsString('2125550147', (string) $blob);
        $this->assertStringNotContainsString("Tony's Slice", (string) $blob);

        $signup = $this->post(route('register.store'), [
            'business_name' => "Tony's Slice",
            'business_type' => 'other',
            'name' => 'Tony Owner',
            'email' => 'tony@example.test',
            'phone' => '+1 (212) 555-0147',
            'password' => 'SignupPassword01!',
            'password_confirmation' => 'SignupPassword01!',
            'country' => 'US',
            'street' => '1 Slice Way',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ]);
        $signup->assertRedirect(route('onboarding.welcome'));

        $newBusiness = Business::query()->where('name', "Tony's Slice")->firstOrFail();
        $this->assertFalse($newBusiness->is_demo);
        $this->assertSame(0, $newBusiness->memberships()->count());
        $this->assertSame(0, $newBusiness->referrals()->count());
        $this->assertSame(0, $newBusiness->rewards()->count());
        $this->assertSame(0, $newBusiness->billingEvents()->count());

        $this->assertSame($newBusiness->id, $attribution->fresh()->signup_business_id);
        $this->assertSame(1, DemoEvent::query()->where('event', 'demo_to_signup')->count());
    }

    public function test_a12_after_expiry_the_prefill_is_gone_but_attribution_still_matches(): void
    {
        $this->completeLoop();
        $this->post(route('demo.exit'), ['from' => 'coda', 'shop_name' => 'Late Shop', 'trade' => 'cafe'])
            ->assertRedirect(route('register'));

        // The live-session carryover is a session leg: with the
        // demo session gone, signup asks normally.
        DemoSession::query()->delete();
        $this->travel(16)->minutes();

        $signup = $this->post(route('register.store'), [
            'business_name' => 'Late Shop',
            'business_type' => 'cafe',
            'name' => 'Late Owner',
            'email' => 'late@example.test',
            'phone' => '+1 (718) 555-0111',
            'password' => 'SignupPassword01!',
            'password_confirmation' => 'SignupPassword01!',
            'country' => 'US',
            'street' => '2 Late Way',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ]);
        $signup->assertRedirect(route('onboarding.welcome'));
        $this->assertSame(1, DemoEvent::query()->where('event', 'demo_to_signup')->count());
    }

    // -- A-8: Juniper absent everywhere else ---------------------------

    public function test_a08_juniper_is_absent_from_every_non_demo_surface(): void
    {
        World::business();
        $this->completeLoop();

        $this->get(route('sitemap'))->assertDontSee('juniper');

        $renderer = app(\App\Services\Share\OgCardRenderer::class);
        $this->get(route('refer.og.card', ['slug' => 'juniper-cafe', 'version' => $renderer->versionFor($this->juniper)]))
            ->assertStatus(404);

        $this->get(route('join.show', ['slug' => 'juniper-cafe']))->assertStatus(404);

        // Juniper's own placements do not open the real flow.
        $qr = \App\Models\QrCode::activeForPlacement($this->juniper, 'counter');
        $this->assertNotNull($qr);
        $landing = $this->get(route('refer.landing', ['token' => $qr->plainToken()]));
        $landing->assertSee('This code is not active');
        $this->assertSame([], $this->verify->starts, 'The real flow never starts a verification for the demo business.');

        // Admin: counts and listings exclude the flag.
        $admin = $this->adminWithTwoFactor();
        $asAdmin = fn () => $this->actingAs($admin, 'admin')->withSession(['admin_auth.two_factor_passed' => true]);
        $home = $asAdmin()->get(route('admin.home'));
        $home->assertOk();
        $home->assertViewHas('businessTotal', Business::query()->where('is_demo', false)->count());
        $asAdmin()->get(route('admin.businesses'))->assertDontSee('Juniper');
        $asAdmin()->get(route('admin.businesses.show', ['business' => $this->juniper->id]))->assertStatus(404);
    }

    // -- A-14: absence sweeps -------------------------------------------

    public function test_a14_absences_phase2_line_resting_line_and_counts(): void
    {
        $home = $this->get(route('home'));
        $home->assertDontSee('or text DEMO to');
        $home->assertDontSee('The demo is resting right now');
        $home->assertSee('id="demo-frame"', false);

        $this->completeLoop();
        foreach ([route('demo.claim'), route('demo.form'), route('demo.reward'), route('demo.coda')] as $url) {
            $page = $this->get($url);
            $page->assertDontSee('businesses use Easy Refer');
            $page->assertDontSee('customers already');
        }
    }

    // -- A-15: session edges ----------------------------------------------

    public function test_a15_expiry_mid_verify_kills_the_code_and_the_session(): void
    {
        $this->startDemo();
        $this->toForm();
        $this->submitForm();
        $code = $this->shownCode();

        $this->travel(16)->minutes();

        $this->get(route('demo.verify'))->assertSee('This demo timed out. Nothing from it was kept.');
        $this->assertSame(0, DemoSession::query()->count());

        $this->verifyWith($code)->assertSee('This demo timed out. Nothing from it was kept.');
        $this->assertSame(0, Referral::query()->count());
    }

    public function test_a15_restart_invalidates_the_prior_session_and_its_token(): void
    {
        $this->startDemo();
        $this->toForm();
        $this->submitForm();
        $code = $this->shownCode();
        $this->verifyWith($code)->assertRedirect(route('demo.reward'));
        [$token] = $this->toCounter();

        // Restart: the old claim and token die with the session.
        $this->startDemo();
        $this->assertSame(0, Referral::query()->count());
        $this->get(route('demo.counter', ['token' => $token]))->assertSee('This code is not active');
    }

    public function test_a15_back_from_reward_mints_no_second_claim(): void
    {
        $this->startDemo();
        $this->toForm();
        $this->submitForm();
        $code = $this->shownCode();
        $this->verifyWith($code)->assertRedirect(route('demo.reward'));

        $this->get(route('demo.form'))->assertRedirect(route('demo.reward'));
        $this->post(route('demo.form.submit'), [
            'first_name' => 'Pat', 'last_name' => 'Reyes', 'phone' => $this->phone, 'consent' => '1',
        ])->assertRedirect(route('demo.reward'));
        $this->assertSame(1, Referral::query()->where('business_id', $this->juniper->id)->count());
    }

    public function test_a15_reseed_zeroes_demo_data_and_strands_live_sessions_on_the_timeout_screen(): void
    {
        $this->completeLoop();
        $this->assertSame(1, Referral::query()->where('business_id', $this->juniper->id)->count());

        $this->artisan('demo:reseed')->assertSuccessful();

        $this->assertSame(0, Referral::query()->where('business_id', $this->juniper->id)->count());
        $this->assertSame(0, Customer::query()->where('phone_e164', 'like', 'demo:%')->count());
        $this->assertSame(0, DemoSession::query()->count());
        $business = app(DemoService::class)->business();
        $this->assertNotNull($business);
        $this->assertNotSame('', trim((string) $business->friend_reward_text));

        $this->get(route('demo.coda'))->assertSee('This demo timed out. Nothing from it was kept.');
    }

    // -- A-16: guards + resting -------------------------------------------

    public function test_a16_eleventh_start_in_an_hour_is_refused(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->startDemo();
        }

        $refused = $this->post(route('demo.start'), ['entry_point' => 'block']);
        $refused->assertOk();
        $refused->assertSee('Too many demo starts from this connection. Try again in a little while.');
    }

    public function test_a16_global_ceiling_rests_the_entries_but_not_a_live_session(): void
    {
        $this->startDemo();
        $this->toForm();
        $this->submitForm();

        config(['demo.guards.global_starts_per_day' => 1]);

        $home = $this->get(route('home'));
        $home->assertSee('The demo is resting right now. Please check back later today.');
        $home->assertDontSee('id="demo-frame"', false);
        $this->get(route('demo.show'))->assertSee('The demo is resting right now.');

        // The in-flight session finishes normally.
        $code = $this->shownCode();
        $this->verifyWith($code)->assertRedirect(route('demo.reward'));
    }

    public function test_a16_two_failed_reseeds_rest_the_demo(): void
    {
        DemoReseedLog::create(['succeeded' => false, 'note' => 'x', 'created_at' => now()]);
        DemoReseedLog::create(['succeeded' => false, 'note' => 'x', 'created_at' => now()]);

        $this->assertTrue(app(DemoService::class)->resting());
        $this->get(route('demo.show'))->assertSee('The demo is resting right now.');
    }

    public function test_a16_alert_fires_at_the_daily_threshold(): void
    {
        Log::spy();
        config(['demo.guards.alert_starts_per_day' => 2]);

        $this->startDemo();
        $this->startDemo();

        Log::shouldHaveReceived('warning')->once();
    }

    // -- The example-number escape -----------------------------------------

    public function test_example_number_escape_runs_the_whole_demo(): void
    {
        $this->startDemo();
        $this->toForm();
        $this->submitForm('(555) 010-0199');
        $verify = $this->get(route('demo.verify'));
        $verify->assertSee('Demo code for +1 (555) 010-0199');
        $code = $this->shownCode();
        $this->verifyWith($code)->assertRedirect(route('demo.reward'));
        $this->assertSame([], $this->sms->sent);
    }

    // -- No em dashes in the demo's own copy --------------------------------

    public function test_no_em_dashes_in_demo_views_or_landing_additions(): void
    {
        foreach (glob(resource_path('views/demo/*.blade.php')) as $file) {
            $this->assertStringNotContainsString('—', (string) file_get_contents($file), basename($file));
        }
        $this->assertStringNotContainsString('—', (string) file_get_contents(resource_path('views/layouts/demo.blade.php')));
    }

    private function adminWithTwoFactor(): \App\Models\User
    {
        $admin = \App\Models\User::create([
            'business_id' => null,
            'role' => 'platform_admin',
            'name' => 'Platform Admin',
            'email' => 'demo-admin@example.test',
            'canonical_email' => CanonicalEmail::of('demo-admin@example.test'),
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
