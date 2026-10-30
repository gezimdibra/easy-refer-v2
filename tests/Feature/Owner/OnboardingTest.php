<?php

namespace Tests\Feature\Owner;

use App\Contracts\SquareCustomerGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Models\PaymentMethod;
use App\Support\Onboarding;
use App\Support\SetupChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakeSquareCustomerGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * P4 onboarding front door (OB-U1..U6). Copy is the design
 * pack's Section 4, PROPOSED (Amendment 28, listed for
 * coordinator ratification in the lane report) and rendered as
 * drafted. Flow state is SetupChecklist's predicates remapped
 * by App\Support\Onboarding; the only stored fact is
 * businesses.onboarding_completed_at.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquarePaymentsGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        $this->app->instance(SquareCustomerGateway::class, new FakeSquareCustomerGateway);

        config([
            'services.square.application_id' => 'sq0idp-test-app',
            'services.square.location_id' => 'LOC_TEST',
            'services.square.environment' => 'sandbox',
        ]);
    }

    /** A business at 0 of 6 flow steps (the own-new shape: a
     *  persisted RS2-2 checkpoint account, so also addressless). */
    private function freshBusiness(): \App\Models\Business
    {
        $business = World::business([
            'billing_status' => 'none',
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
            'email_verified_at' => null,
            'country' => null,
            'street' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
        ]);
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => null])->save();

        return $business->fresh();
    }

    public function test_the_only_new_stored_fact_is_the_completion_timestamp(): void
    {
        $this->assertTrue(Schema::hasColumn('businesses', 'onboarding_completed_at'));
        $business = World::business();
        $this->assertNull($business->onboarding_completed_at);
    }

    public function test_welcome_teaches_the_loop_and_names_the_six_steps(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('onboarding.welcome'));

        $response->assertOk();
        $response->assertSee('Your customers are about to do your marketing.');
        $response->assertSee('A customer scans your code and sends it to a friend.');
        $response->assertSee('A visit like that is what you pay for, and your first 5 are free.');
        $response->assertSee('Setting up takes less than 2 minutes: six short steps. Your code works at the end. Your first 5 visits are free, and adding a card keeps your code going after that.');
        $response->assertSee('Start setup');
        $response->assertSee('Not now');
        // O-2: the step names preview.
        foreach (['Rewards', 'Card', 'Location', 'Verify', 'Poster', 'Try it'] as $label) {
            $response->assertSee($label);
        }
        // The flow is a focused shell: no app rail.
        $response->assertDontSee('aria-label="Primary"', false);
    }

    public function test_flow_order_is_rewards_card_location_verify_poster_test(): void
    {
        $this->assertSame(['rewards', 'card', 'location', 'verify', 'poster', 'test'], Onboarding::STEPS);
        $this->assertSame('card', Onboarding::nextStep('rewards'));
        $this->assertSame('location', Onboarding::nextStep('card'));
        $this->assertSame('verify', Onboarding::nextStep('location'));
        $this->assertSame('poster', Onboarding::nextStep('verify'));
        $this->assertSame('test', Onboarding::nextStep('poster'));
        $this->assertSame('finish', Onboarding::nextStep('test'));
        $this->assertSame(3, Onboarding::position('location'));
        $this->assertSame(4, Onboarding::position('verify'));
    }

    public function test_step_states_are_the_checklists_own_predicates(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        $states = Onboarding::states($business, $owner);
        $this->assertSame([
            'rewards' => false, 'card' => false, 'location' => false, 'verify' => false, 'poster' => false, 'test' => false,
        ], $states);
        $this->assertSame('rewards', Onboarding::firstOpenStep($states));
        $this->assertTrue(Onboarding::isIncomplete($business, $owner));

        // Doing the rewards step out of band (the settings path)
        // moves the resume position: state, not a cursor.
        $business->forceFill(['friend_reward_text' => 'A free coffee'])->save();
        $states = Onboarding::states($business->fresh(), $owner);
        $this->assertTrue($states['rewards']);
        // RE-DERIVED (RS2-2 merge ruling, 2026-10-30): the
        // resume position is the first open step in FLOW ORDER
        // — Card. The activation train's skip-the-card targeting
        // retired: the card never gates the count or the flow,
        // and its own skip is one tap, so being pointed at it
        // cannot strand an owner (the cold walk below visits
        // it directly either way).
        $this->assertSame('card', Onboarding::firstOpenStep($states));
    }

    public function test_each_working_step_renders_with_its_progress_position(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        $expect = [
            'rewards' => ['Step 1 of 6', 'What do you want to give a new customer who comes in from a referral?'],
            'card' => ['Step 2 of 6', 'Set your reward first, then this step keeps your code working.'],
            'location' => ['Step 3 of 6', 'Where is your business?'],
            'verify' => ['Step 4 of 6', 'Check your inbox.'],
            'poster' => ['Step 5 of 6', 'Where will customers see it?'],
            'test' => ['Step 6 of 6', 'See it work, as your customer.'],
        ];

        foreach ($expect as $step => [$position, $heading]) {
            $response = $this->actingAs($owner)->get(route('onboarding.step', $step));
            $response->assertOk();
            $response->assertSee($position);
            $response->assertSee($heading);
            $response->assertSee('Skip setup for now');
        }
    }

    public function test_unknown_step_is_a_designed_404(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('onboarding.step', 'bogus'))->assertNotFound();
    }

    public function test_completed_business_is_retired_from_the_flow(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);
        $business->forceFill(['onboarding_completed_at' => now()])->save();

        $this->actingAs($owner)->get(route('onboarding.welcome'))->assertRedirect(route('dashboard'));
        $this->actingAs($owner)->get(route('onboarding.step', 'card'))->assertRedirect(route('dashboard'));
    }

    public function test_rewards_save_through_the_one_write_path_opens_the_next_step(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        $this->actingAs($owner)
            ->post(route('owner.reward-texts'), [
                'friend_reward_text' => 'A free coffee',
                'referrer_reward_text' => 'A free pastry',
            ])
            ->assertRedirect();

        $states = Onboarding::states($business->fresh(), $owner);
        $this->assertTrue($states['rewards']);

        $response = $this->actingAs($owner)->get(route('onboarding.step', 'rewards'));
        $response->assertSee('Reward set.');
        $response->assertSee('Continue');
    }

    /**
     * S6, the named scenario (OB-U1 acceptance, from the
     * coherence audit): a failed card save must never read as
     * success anywhere. The card step stays open in the flow,
     * in the checklist, and on the finish screen.
     */
    public function test_s6_failed_card_save_leaves_the_card_step_open(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);
        $this->gateway->cardFailureCode = 'GENERIC_DECLINE';

        $response = $this->actingAs($owner)->post(route('onboarding.card.store'), [
            'source_id' => 'cnon:declined',
        ]);

        $response->assertRedirect(route('onboarding.step', 'card'));
        $response->assertSessionHas('onboarding_card_failed');

        $this->assertNull(PaymentMethod::defaultFor($business));
        $checklist = SetupChecklist::for($business->fresh(), $owner);
        $this->assertFalse($checklist['card_on_file'], 'S6: a failed save left the checklist card step done.');
        $states = Onboarding::states($business->fresh(), $owner);
        $this->assertFalse($states['card'], 'S6: a failed save left the flow card step done.');

        $page = $this->actingAs($owner)->get(route('onboarding.step', 'card'));
        $page->assertSee('That card did not go on. Nothing was charged. Check the number and try again, or use a different card.');
        $page->assertDontSee('Your code is on.');
    }

    public function test_card_success_shows_the_o4_state_before_advancing(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->post(route('onboarding.card.store'), [
            'source_id' => 'cnon:ok',
        ]);

        $response->assertRedirect(route('onboarding.step', 'card'));
        $response->assertSessionHas('onboarding_card_saved');
        $this->assertNotNull(PaymentMethod::defaultFor($business));

        // FLOW-STATE TRUTH train: freshBusiness has NO reward
        // set, so at this card success the reward gate is still
        // open and the code is NOT on — the old pin asserted
        // the A-D2 lie in exactly this state. The O-4 success
        // state still shows before advancing; it names the
        // remaining gate. (ACTIVATION-REDESIGN-OWNED wording.)
        $page = $this->actingAs($owner)->get(route('onboarding.step', 'card'));
        // RE-DERIVED (activation redesign, census C-07): the
        // success panel states the gate at render time. This
        // fixture has no reward set yet, so the honest form is
        // ACT-CARD-04b; ActivationAllowanceTest P8 pins 04a
        // (reward set, code on) in the same step.
        $page->assertSee('Card saved. Your code turns on once you set your friend reward.');
        $page->assertDontSee('Card saved. Your code is on.');
        $page->assertSee('Continue');

        // With the reward set, the same success state makes
        // the code-on claim — and now it is the gate's truth.
        $business->forceFill(['friend_reward_text' => '$10 off your first visit'])->save();
        $owner->unsetRelation('business');
        $this->actingAs($owner)
            ->withSession(['onboarding_card_saved' => true])
            ->get(route('onboarding.step', 'card'))
            ->assertSee('Your code is on. Nothing is charged today. Your first 5 visits are free.');

        $states = Onboarding::states($business->fresh(), $owner);
        $this->assertTrue($states['card']);
    }

    public function test_card_step_carries_the_removal_honesty_line_o3(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('onboarding.step', 'card'));
        // RE-DERIVED (activation redesign, census C-08,
        // ACT-CARD-05): removal re-enters the allowance state,
        // so the line names the pause at the end of the free
        // visits, never keeps-counting. The skip is a plain
        // full button now (ACT-CARD-06): the code is not off.
        $response->assertSee('You can take your card off any time in Settings → Billing, or end your program any time in Settings → Account. Taking the card off pauses charges. Visits keep counting while your free visits last, and your code pauses when they run out if no card is on file.');
        $response->assertSee('Skip for now');
        $response->assertSee('Your code works for your first 5 visits with no card. You can add a card any time in Settings → Billing.');
        $response->assertDontSee('Skip, my code stays off for now');
        // ACT-CARD-03: the price paragraph ends with the
        // appended sentence.
        $response->assertSee('Your first 5 visits are free, so nothing is charged today.');
        $response->assertSee('Nothing is charged until your free visits are used.');
    }

    public function test_poster_step_offers_the_spot_choice_with_previews_and_the_heads_up(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('onboarding.step', 'poster'));
        $response->assertOk();
        foreach (['Counter', 'Window', 'Table', 'Receipt'] as $spot) {
            $response->assertSee($spot);
        }
        // [MIX-04] (Amendment 31, micro-lane addendum to Lane A):
        // the onboarding poster step duplicates the QR page's
        // spot lines under a "one language in both places" rule,
        // so it renders the same dimension lines verbatim, and
        // the superseded [MIX-03] lines render nowhere here.
        $response->assertSee('By the register, where people pay. Prints on half a sheet of paper (5.8 x 8.3 in).');
        $response->assertSee('In the front window, facing out. A full sheet of paper (8.3 x 11.7 in), so people can read it from the sidewalk.');
        $response->assertSee('Standing up on your tables. The sheet is 8.3 x 5.8 in and folds in half to stand.');
        $response->assertSee('Handed out with the receipt. A small card, 3.3 x 2.2 in, the size of a business card.');
        $response->assertDontSee('By the register, where people pay. Prints on half a sheet of paper.');
        $response->assertDontSee('In the front window, facing out. A full page, so people can read it from the sidewalk.');
        $response->assertDontSee('Standing up on your tables. A small folded card.');
        $response->assertDontSee('Handed out with the receipt. A small card.');
        // [ON-QR-1]: the retired Screen poster is neither named
        // nor linked on the onboarding poster step.
        $response->assertDontSee('Screen poster');
        $response->assertDontSee('For a tablet or TV screen');
        $response->assertSee('Print this poster');
        $response->assertSee('Save it instead');
        $response->assertSee('preview=1', false);
        // No card and no rewards yet: the not-active heads-up
        // rides along. FLOW-STATE TRUTH train: with BOTH gates
        // open it names both missing steps (the reward-only
        // turns-on line was false in this state, A-D1 class);
        // the F2-GATE-01 reward-only line survives for the
        // card-present state (MissingRewardTextGateTest).
        $response->assertSee('This code is not active yet. Set your friend reward and add a card to turn it on.');

        // With the flow active the heads-up is gone (a captured
        // card flips billing_status to active, as the service
        // does; Amendment 40: the friend reward must also be
        // set for the flow to count as active).
        // The relation unset mirrors a fresh production request:
        // the test harness reuses one user instance across calls.
        $owner->unsetRelation('business');
        $business->forceFill(['billing_status' => 'active', 'friend_reward_text' => '$10 off your first visit'])->save();
        \App\Models\PaymentMethod::create([
            'business_id' => $business->id, 'square_card_id' => 'card_test_1',
            'card_brand' => 'VISA', 'last4' => '5858', 'exp_month' => 3, 'exp_year' => 2028,
            'is_default' => true, 'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_onboarding',
        ]);
        $live = $this->actingAs($owner)->get(route('onboarding.step', 'poster'));
        $live->assertDontSee('This code is not active yet.');
    }

    public function test_test_step_detects_the_owners_own_join(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        // Amendment 38 (F-17): the waiting line renders only
        // while the flow is live, so the waiting leg runs with
        // the flow on (card saved, friend reward set). The
        // inactive state has its own pin (TrainA3TruthTest).
        $business->forceFill(['billing_status' => 'active', 'friend_reward_text' => '$10 off your first visit'])->save();
        \App\Models\PaymentMethod::create([
            'business_id' => $business->id, 'square_card_id' => 'card_test_step',
            'card_brand' => 'VISA', 'last4' => '5858', 'exp_month' => 3, 'exp_year' => 2028,
            'is_default' => true, 'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_teststep',
        ]);

        $waiting = $this->actingAs($owner)->get(route('onboarding.step', 'test'));
        $waiting->assertSee('Waiting for your join');
        $waiting->assertSee('Skip the test');

        $owner->forceFill(['phone_e164' => '+15557650109'])->save();
        $customer = World::customer('+15557650109');
        World::membership($business, $customer);

        $done = $this->actingAs($owner)->get(route('onboarding.step', 'test'));
        $done->assertSee('It worked, continue');
        $this->assertTrue(Onboarding::states($business->fresh(), $owner->fresh())['test']);
    }

    /**
     * The cold walk (lane acceptance): a brand-new business in
     * the own-new shape (no rewards, no card, unverified, no
     * poster, no test join) walks the whole front door over
     * HTTP and arrives LIVE AND PROVEN, then completes.
     */
    public function test_cold_walk_own_new_through_all_steps_to_live(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        // Entry: welcome, Start setup lands on the first open step.
        $this->actingAs($owner)->get(route('onboarding.welcome'))->assertOk();
        $this->assertSame('rewards', Onboarding::firstOpenStep(Onboarding::states($business, $owner)));

        // Step 1, Rewards: save the pair through the one write path.
        $this->actingAs($owner)->post(route('owner.reward-texts'), [
            'friend_reward_text' => 'A free regular coffee',
            'referrer_reward_text' => 'A free pastry',
        ])->assertRedirect();
        $owner->unsetRelation('business');
        // The resume position is the next step in flow order:
        // Card (RS2-2 merge ruling, 2026-10-30).
        $this->assertSame('card', Onboarding::firstOpenStep(Onboarding::states($business->fresh(), $owner)));

        // Step 2, Card: a good card through the fake gateway.
        $this->actingAs($owner)->post(route('onboarding.card.store'), ['source_id' => 'cnon:ok'])
            ->assertRedirect(route('onboarding.step', 'card'));
        $owner->unsetRelation('business');
        $this->assertTrue($business->fresh()->flowIsActive());
        $this->assertSame('location', Onboarding::firstOpenStep(Onboarding::states($business->fresh(), $owner)));

        // Step 3, Location: the address that used to be signup
        // Step 4 (RS2-2). The flow was already active without it
        // (the S6 state: card + reward done, Location not yet).
        $this->actingAs($owner)->post(route('onboarding.location.store'), [
            'country' => 'US',
            'street' => '1 Main Street',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ])->assertRedirect(route('onboarding.step', 'verify'));
        $owner->unsetRelation('business');
        $this->assertSame('verify', Onboarding::firstOpenStep(Onboarding::states($business->fresh(), $owner)));

        // Step 4, Verify: the signed link returns into the flow.
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $owner->id,
            'hash' => sha1($owner->getEmailForVerification()),
        ]);
        $this->actingAs($owner)->get($url)->assertRedirect(route('onboarding.step', 'verify'));
        $owner->unsetRelation('business');
        $this->actingAs($owner)->get(route('onboarding.step', 'verify'))->assertSee('Email verified');
        $this->assertSame('poster', Onboarding::firstOpenStep(Onboarding::states($business->fresh(), $owner->fresh())));

        // Step 5, Poster: printing the counter poster completes it.
        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'counter', 'print' => 1]))->assertOk();
        $this->assertSame('test', Onboarding::firstOpenStep(Onboarding::states($business->fresh(), $owner->fresh())));

        // Step 6, Try it: the owner's own join lands.
        $owner->forceFill(['phone_e164' => '+15557650109'])->save();
        World::membership($business, World::customer('+15557650109'));
        $this->actingAs($owner)->get(route('onboarding.step', 'test'))->assertSee('It worked, continue');
        $this->assertNull(Onboarding::firstOpenStep(Onboarding::states($business->fresh(), $owner->fresh())));

        // Finish: LIVE AND PROVEN, then completion retires the flow.
        $finish = $this->actingAs($owner)->get(route('onboarding.finish'));
        $finish->assertOk();
        $finish->assertSee("You're live, {$business->name}.", false);
        $finish->assertSee('You already proved it: the join you just made came through your own poster.');

        $this->actingAs($owner)->post(route('onboarding.complete'), ['door' => 'dashboard'])
            ->assertRedirect(route('dashboard'));
        $this->assertNotNull($business->fresh()->onboarding_completed_at);
        $this->actingAs($owner)->get(route('onboarding.welcome'))->assertRedirect(route('dashboard'));
    }

    public function test_dashboard_resume_banner_counts_flow_steps_and_points_at_the_first_open_one(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('dashboard'));
        $page->assertOk();
        $page->assertSee("You're partway through setup, 0 of 5 required steps done.", false);
        $page->assertSee('Pick up where you left off');
        $page->assertSee(route('onboarding.step', 'rewards'), false);

        $business->forceFill(['friend_reward_text' => 'A free coffee'])->save();
        $owner->unsetRelation('business');

        $page = $this->actingAs($owner)->get(route('dashboard'));
        $page->assertSee("You're partway through setup, 1 of 5 required steps done.", false);
        // The banner points at the first open step in flow
        // order: Card (RS2-2 merge ruling, 2026-10-30 — the
        // count above stays required-only: 1 of 4).
        $page->assertSee(route('onboarding.step', 'card'), false);
    }

    public function test_resume_banner_hides_with_the_checklist_dismissal_and_after_completion(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('dashboard.checklist.dismiss'))->assertRedirect();
        $this->actingAs($owner)->get(route('dashboard'))
            ->assertDontSee('partway through setup');

        $business->forceFill(['onboarding_completed_at' => now()])->save();
        $owner->unsetRelation('business');
        $this->actingAs($owner)->get(route('dashboard'))
            ->assertDontSee('partway through setup');
    }

    public function test_checklist_rows_repoint_into_the_flow_while_it_is_open(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        $page = $this->actingAs($owner)->get(route('dashboard'));
        $page->assertSee(route('onboarding.step', 'verify'), false);
        $page->assertSee(route('onboarding.step', 'rewards'), false);
        $page->assertSee(route('onboarding.step', 'card'), false);
        $page->assertSee(route('onboarding.step', 'poster'), false);
        $page->assertSee(route('onboarding.step', 'test'), false);
        // The review-link row is not a flow step: it keeps its home.
        $page->assertSee(route('settings.reviews'), false);

        $business->forceFill(['onboarding_completed_at' => now()])->save();
        $owner->unsetRelation('business');

        $page = $this->actingAs($owner)->get(route('dashboard'));
        $page->assertSee(route('settings.billing'), false);
        $page->assertDontSee(route('onboarding.step', 'card'), false);
    }

    public function test_action_center_setup_row_resumes_the_flow_then_the_dashboard(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $business->forceFill(['email_verified_at' => now()])->save();

        $page = $this->actingAs($owner)->get(route('actions.index'));
        $page->assertOk();
        $page->assertSee('Your setup is not finished');
        $page->assertSee(route('onboarding.step', 'rewards'), false);

        $business->forceFill(['onboarding_completed_at' => now()])->save();
        $owner->unsetRelation('business');

        $page = $this->actingAs($owner)->get(route('actions.index'));
        $page->assertSee('Your setup is not finished');
        $page->assertDontSee(route('onboarding.step', 'rewards'), false);
    }

    public function test_card_step_carries_the_fe_card_01_pristine_guard(): void
    {
        // OB-U4: the extraction pins the guard to BOTH hosts;
        // the billing host is pinned by BillingCardPristineGuardTest.
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('onboarding.step', 'card'));
        $response->assertOk();
        $response->assertSee('currentState.isEmpty', false);
        $response->assertSee('Enter your card details first.');
    }

    public function test_rewards_step_offers_the_starter_packs_with_shipped_grammar(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        // OR-1 (Amendment 49): the step offers ONE prefilled
        // reward (the first pack, in the field) and the other
        // packs behind "See other ideas" — single rewards, no
        // two-sided grammar anywhere on the step.
        $response = $this->actingAs($owner)->get(route('onboarding.step', 'rewards'));
        $response->assertOk();
        foreach (\App\Support\StarterPacks::forType($business->business_type) as $pack) {
            $response->assertSee($pack['reward']);
            $response->assertSee($pack['cost']);
        }
        $response->assertSee('Referral Reward');
        $response->assertSee('See other ideas');
        $response->assertSee('Typical cost:');
        $response->assertDontSee('For the friend:');
        $response->assertDontSee('For the referrer:');
        // Amendment 40: the skip line states the true
        // consequence (the code stays off), superseding the
        // poster-only framing.
        $response->assertSee('Skipping leaves your code off until you set a reward: customers cannot join yet.');
    }

    public function test_choosing_a_pack_saves_its_single_reward_through_the_one_write_path(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);
        $pack = \App\Support\StarterPacks::forType($business->business_type)[1];

        // OR-1: the pack's ONE reward posts through the one
        // write path and stores UNIFIED (referrer NULL, flag off).
        $this->actingAs($owner)->post(route('owner.reward-texts'), [
            'friend_reward_text' => $pack['reward'],
        ])->assertRedirect();

        $fresh = $business->fresh();
        $this->assertSame($pack['reward'], $fresh->friend_reward_text);
        $this->assertNull($fresh->referrer_reward_text);
        $this->assertFalse($fresh->rewardsAreSplit());
        $this->assertTrue(Onboarding::states($fresh, $owner)['rewards']);
    }

    public function test_verify_step_shows_the_done_state_once_verified(): void
    {
        $business = $this->freshBusiness();
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $business->forceFill(['email_verified_at' => now()])->save();

        $response = $this->actingAs($owner)->get(route('onboarding.step', 'verify'));
        $response->assertOk();
        $response->assertSee('Email verified');
        $response->assertSee('Continue');
        $this->assertTrue(Onboarding::states($business->fresh(), $owner->fresh())['verify']);
    }

    public function test_finish_not_live_state_and_completion_by_the_billing_door(): void
    {
        $business = $this->freshBusiness();
        // RE-DERIVED (activation redesign, census C-09,
        // 2026-10-30): this pin's old state — rewards set, card
        // missing — is LIVE under the allowance model, so it
        // renders the live finish (ActivationAllowanceTest P8).
        // The not-live finish that completes by the billing
        // door is now the allowance-exhausted state
        // (ACT-FIN-03): five counted visits seeded on the
        // ledger, no card.
        $business->forceFill(['friend_reward_text' => '$10 off your first visit'])->save();
        $owner = World::owner($business);
        $referrer = World::customer('+12125550601', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        for ($i = 0; $i < 5; $i++) {
            $friend = World::customer('+1212555061'.(string) $i, 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);
            \App\Models\BillingEvent::create([
                'business_id' => $business->id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }

        $response = $this->actingAs($owner)->get(route('onboarding.finish'));
        $response->assertOk();
        // ACT-FIN-03 verbatim: its own heading and body.
        $response->assertSee('Your code was live, and it is paused now.');
        $response->assertSee('Your 5 free visits are used up. Add a card to turn your code back on. Nothing is charged when you add your card.');
        $response->assertSee('Add a card now');
        $response->assertDontSee('Everything is ready except a card on file.');

        $this->actingAs($owner)
            ->post(route('onboarding.complete'), ['door' => 'billing'])
            ->assertRedirect(route('settings.billing').'#card');

        $this->assertNotNull($business->fresh()->onboarding_completed_at);
        $this->assertFalse(Onboarding::isIncomplete($business->fresh(), $owner));
    }

    public function test_completion_stamps_exactly_once_and_a_second_complete_does_not_move_it(): void
    {
        // INV-01 (merge review, 2026-10-26): the lane described
        // this leg but had not pinned it. Completion flips the
        // stored fact once; passing the finish screen again must
        // not move the stamp.
        $business = $this->freshBusiness();
        $owner = World::owner($business);

        $this->actingAs($owner)
            ->post(route('onboarding.complete'), ['door' => 'dashboard'])
            ->assertRedirect(route('dashboard'));

        $first = $business->fresh()->onboarding_completed_at;
        $this->assertNotNull($first);

        $this->actingAs($owner)
            ->post(route('onboarding.complete'), ['door' => 'dashboard'])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(
            $first->equalTo($business->fresh()->onboarding_completed_at),
            'A second completion moved the onboarding_completed_at stamp.',
        );
    }
}
