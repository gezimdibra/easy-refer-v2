<?php

namespace Tests\Feature;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Contracts\SquareCustomerGateway;
use App\Contracts\SquarePaymentsGateway;
use App\Mail\WelcomeMail;
use App\Models\BillingEvent;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\User;
use App\Services\Billing\CardOnFileService;
use App\Services\Billing\MonthlyCharger;
use App\Services\Billing\SettlementService;
use App\Services\Billing\VisitValuation;
use App\Services\Referral\RedemptionService;
use App\Support\BillingCopy;
use App\Support\Onboarding;
use App\Support\SetupChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Fakes\FakeSquareCustomerGateway;
use Tests\Fakes\FakeSquarePaymentsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ACTIVATION REDESIGN acceptance pins (owner ruling 2026-10-30;
 * design pass night-loop/activation-redesign-design-pass.md
 * Section 11, P1-P10). The card leg of activation is replaced
 * by the allowance leg: no card + free visits left = live; the
 * 5th counted visit pauses the code until a card is added.
 * Pin IDs below are the pass's own.
 *
 * TWO-KEY NOTE: this file pins the gate predicate and the
 * settlement-adjacent behavior for the independent two-key
 * review; it does not approve them.
 */
class ActivationAllowanceTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsGateway $sms;

    private FakeSquarePaymentsGateway $gateway;

    private int $friendSequence = 9100;

    private int $referrerSequence = 9200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsGateway;
        $this->gateway = new FakeSquarePaymentsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->app->instance(SquarePaymentsGateway::class, $this->gateway);
        $this->app->instance(SquareCustomerGateway::class, new FakeSquareCustomerGateway);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        config(['billing.charges_enabled' => true]);
        Mail::fake();
    }

    /** A business with rewards set and NO card (Step 14's shape). */
    private function cardless(array $overrides = []): Business
    {
        return World::business(array_merge(['billing_status' => 'none'], $overrides));
    }

    private function addCard(Business $business): PaymentMethod
    {
        $method = PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'sq_card_act_'.$business->id,
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_act_'.$business->id.'_'.uniqid(),
        ]);
        $business->forceFill(['billing_status' => 'active'])->save();

        return $method;
    }

    /** A referrer membership aged past the 25-minute redemption gap anchor. */
    private function referrer(Business $business): Membership
    {
        $customer = World::customer('+1212555'.str_pad((string) $this->referrerSequence++, 4, '0', STR_PAD_LEFT), 'Reg', 'Ular');
        [$membership] = World::membership($business, $customer);
        $membership->forceFill(['created_at' => now()->subDays(2)])->save();

        return $membership->fresh();
    }

    /** A claimed (pending_redemption) referral, aged past the soft age signal. */
    private function claimed(Membership $membership): Referral
    {
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        $referral->forceFill(['created_at' => now()->subHours(3)])->save();

        return $referral->fresh();
    }

    /** Redeem one claimed referral at the counter; returns the service result. */
    private function redeem(Referral $referral): array
    {
        $result = app(RedemptionService::class)->redeemReferral($referral->fresh(), null, '127.0.0.1');
        $this->assertSame('redeemed', $result['outcome']);

        return $result;
    }

    private function seedParticipant(Business $business, string $coverage = 'billable'): BillingEvent
    {
        $membership = $this->referrer($business);
        $friend = World::customer('+1212555'.str_pad((string) $this->friendSequence++, 4, '0', STR_PAD_LEFT), 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);

        return BillingEvent::create([
            'business_id' => $business->id,
            'referral_id' => $referral->id,
            'amount_cents' => 200,
            'coverage' => $coverage,
        ]);
    }

    // ---------------------------------------------------------------
    // P1: LIVE WITHOUT A CARD
    // ---------------------------------------------------------------

    public function test_p1_cardless_business_is_live_and_the_first_visit_settles_free(): void
    {
        $business = $this->cardless();
        $owner = World::owner($business);
        [, $qrToken] = World::qr($business);

        $this->assertTrue($business->flowIsActive());
        $this->assertNull($business->flowBlockReason());
        $this->assertSame(5, $business->allowanceLeft());

        // The customer flow is live: the store QR landing renders.
        $this->get(route('refer.landing', ['token' => $qrToken]))->assertOk();

        // Enrollment completes through the live flow.
        $this->carryFlowCookie($this->get(route('refer.landing', ['token' => $qrToken]))->assertOk());
        $this->carryFlowCookie($this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '+12125550111', 'consent' => '1',
        ])->assertRedirect(route('refer.verify')));
        $this->carryFlowCookie($this->post(route('refer.verify.check'), ['code' => '123456'])
            ->assertRedirect(route('refer.share')));

        // A claimed visit redeems at the counter and settles free.
        $membership = $this->referrer($business);
        $this->redeem($this->claimed($membership));
        app(SettlementService::class)->settleAndPersist($business->fresh());
        $event = BillingEvent::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('free_credit', $event->coverage);
        $this->assertSame(4, $business->fresh()->allowanceLeft());

        // Absence direction: no owner surface states the retired rule.
        foreach ([route('dashboard'), route('settings.billing'), route('owner.qr')] as $url) {
            $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('required to activate', $html);
            $this->assertStringNotContainsString('Add a card to turn on', $html);
            $this->assertStringNotContainsString('my code stays off', $html);
        }
    }

    private function carryFlowCookie(\Illuminate\Testing\TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'er2_flow') {
                $this->withUnencryptedCookie('er2_flow', $cookie->getValue());
            }
        }
    }

    // ---------------------------------------------------------------
    // P2: THE COUNT IS ONE COUNT (held mix; release can pause)
    // ---------------------------------------------------------------

    public function test_p2_held_mix_one_count_and_release_pauses(): void
    {
        // Daily cap 1: a referrer's second same-day redemption holds.
        $business = $this->cardless(['daily_referral_cap' => 1]);
        $owner = World::owner($business);

        $a = $this->referrer($business);
        $b = $this->referrer($business);
        $c = $this->referrer($business);

        $this->redeem($this->claimed($a));            // clean 1
        $this->redeem($this->claimed($b));            // clean 2
        $this->redeem($this->claimed($c));            // clean 3
        $held = $this->redeem($this->claimed($a));    // held (velocity_daily)
        $heldEvent = BillingEvent::query()->where('referral_id', $held['reward']->referral_id)->firstOrFail();
        $this->assertSame('held', $heldEvent->coverage);
        $this->redeem($this->claimed($b));            // held too (cap) — participants still 3? no:
        // b's second redemption also holds under cap 1; use a fresh referrer for clean 4.
        $d = $this->referrer($business);
        $this->redeem($this->claimed($d));            // clean 4

        $fresh = $business->fresh();
        $this->assertSame(4, $fresh->allowanceUsed());
        $this->assertSame(1, $fresh->allowanceLeft());
        $this->assertTrue($fresh->flowIsActive());

        // The walk would settle exactly 4 free_credit.
        $settlement = app(VisitValuation::class)->settle($fresh);
        $freeCount = collect($settlement['settled'])->filter(fn ($row) => ($row['coverage'] ?? null) === 'free_credit')->count();
        $this->assertSame(4, $freeCount);

        // Tile and panel print the gate's number: 1.
        $billing = $this->actingAs($owner)->get(route('settings.billing'))->assertOk();
        $billing->assertSee('1 of 5');
        $billing->assertSee('You have 1 of your 5 free visits left.');

        // Release the held reward: the event joins the walk, the
        // last slot goes, and the code pauses with NO new redemption.
        app(RedemptionService::class)->releaseReward($held['reward']->fresh());
        $fresh = $business->fresh();
        $this->assertSame(0, $fresh->allowanceLeft());
        $this->assertFalse($fresh->flowIsActive());
        $this->assertSame('allowance_exhausted', $fresh->flowBlockReason());
    }

    // ---------------------------------------------------------------
    // P3: DENIED AND REFUNDED NEVER COUNT; deny restores + un-pauses
    // ---------------------------------------------------------------

    public function test_p3_denied_and_refunded_never_count_and_deny_restores_a_slot(): void
    {
        $business = $this->cardless();

        // Head-of-ledger terminal rows consume nothing.
        $this->seedParticipant($business, 'fraud_denied');
        $this->seedParticipant($business, 'refunded');
        $this->assertSame(5, $business->fresh()->allowanceLeft());

        // Five clean visits land; the fifth pauses the code.
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->redeem($this->claimed($this->referrer($business)));
        }
        app(SettlementService::class)->settleAndPersist($business->fresh());
        $fresh = $business->fresh();
        $this->assertSame(0, $fresh->allowanceLeft());
        $this->assertFalse($fresh->flowIsActive());

        // RE-DERIVED at the activation merge (2026-10-30) — the
        // r26 deny-restores-a-slot path this pin was written
        // against no longer exists in the shipped product: the
        // Breaker HIGH fix (dedupe train, two-key reviewed)
        // locked denyReward to HELD rewards only, so a counted,
        // never-held visit's reward refuses deny here and the
        // allowance does not move. The slot-restore derivation
        // itself is pinned below at the data layer: when a
        // counted event's coverage becomes fraud_denied (the
        // manual fraud-denied decision), the slot restores and
        // the code comes back WITHOUT a card.
        $this->assertFalse(app(RedemptionService::class)->denyReward($results[0]['reward']->fresh()));
        $fresh = $business->fresh();
        $this->assertSame(0, $fresh->allowanceLeft());
        $this->assertFalse($fresh->flowIsActive());

        \App\Models\BillingEvent::query()->where('referral_id', $results[0]['referral']->id)->firstOrFail()->forceFill(['coverage' => 'fraud_denied'])->save();
        $fresh = $business->fresh();
        $this->assertSame(1, $fresh->allowanceLeft());
        $this->assertTrue($fresh->flowIsActive());
        $this->assertNull($fresh->flowBlockReason());
    }

    // ---------------------------------------------------------------
    // P4: THE 5TH VISIT AND THE IN-FLIGHT TAIL
    // ---------------------------------------------------------------

    public function test_p4_fifth_visit_pauses_and_in_flight_claim_still_completes(): void
    {
        $business = $this->cardless();
        [, $qrToken] = World::qr($business);
        $membership = $this->referrer($business);

        // Six claims complete while the code is live.
        $claims = [];
        for ($i = 0; $i < 6; $i++) {
            $claims[] = $this->claimed($this->referrer($business));
        }

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->redeem($claims[$i]);
        }

        // The 5th visit's own parties are whole: the referrer's
        // reward exists, vesting from the 5th redemption.
        $this->assertNotNull($results[4]['reward']);
        $this->assertSame(Referral::STATE_REDEEMED, $claims[4]->fresh()->state);

        $fresh = $business->fresh();
        $this->assertFalse($fresh->flowIsActive());
        $this->assertSame('allowance_exhausted', $fresh->flowBlockReason());

        app(SettlementService::class)->settleAndPersist($fresh);
        $coverages = BillingEvent::query()->where('business_id', $business->id)->orderBy('id')->pluck('coverage')->all();
        $this->assertSame(['free_credit', 'free_credit', 'free_credit', 'free_credit', 'free_credit'], $coverages);

        // The sixth referral, claimed BEFORE the 5th redemption,
        // still redeems after the pause (redemption is never
        // gated) and settles billable, accruing with no card.
        $this->redeem($claims[5]);
        app(SettlementService::class)->settleAndPersist($business->fresh());
        $sixth = BillingEvent::query()->where('business_id', $business->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame('billable', $sixth->coverage);
        $this->assertNull(app(MonthlyCharger::class)->charge($business->fresh()));
        $this->assertCount(0, $this->gateway->chargeCalls);

        // A seventh claim attempted after the pause meets the
        // allowance pause's OWN friend page, never the generic
        // not-active page, and creates no referral.
        $before = Referral::query()->where('business_id', $business->id)->count();
        $landing = $this->get(route('refer.landing', ['token' => $qrToken]));
        $landing->assertStatus(404);
        $landing->assertSee('New visits are paused here for now');
        $landing->assertSee($business->name);
        $landing->assertSee('If you already claimed a reward here, show your code at the counter as usual.');
        $landing->assertDontSee('This code is not active');

        $friend = $this->get(route('refer.friend', ['token' => $membership->plainShareToken()]));
        $friend->assertStatus(404);
        $friend->assertSee('New visits are paused here for now');
        $this->assertSame($before, Referral::query()->where('business_id', $business->id)->count());
    }

    // ---------------------------------------------------------------
    // P5: CARD REMOVAL RE-ENTERS
    // ---------------------------------------------------------------

    public function test_p5_card_removal_reenters_the_allowance(): void
    {
        // Allowance left: the flow stays on, carried by the allowance.
        $business = $this->cardless();
        $card = $this->addCard($business);
        for ($i = 0; $i < 3; $i++) {
            $this->seedParticipant($business->fresh());
        }
        app(CardOnFileService::class)->removeCard($business->fresh(), $card->fresh());
        $fresh = $business->fresh();
        $this->assertFalse($fresh->hasCardOnFile());
        $this->assertTrue($fresh->flowIsActive());
        $this->assertSame(2, $fresh->allowanceLeft());

        // Two more counted visits land the pause.
        $this->seedParticipant($fresh);
        $this->seedParticipant($fresh);
        $this->assertFalse($business->fresh()->flowIsActive());
        $this->assertSame('allowance_exhausted', $business->fresh()->flowBlockReason());

        // Allowance spent: the removal confirmation warns BEFORE
        // the press, and removal itself creates the pause.
        $spent = $this->cardless();
        $spentOwner = World::owner($spent);
        $spentCard = $this->addCard($spent);
        for ($i = 0; $i < 5; $i++) {
            $this->seedParticipant($spent->fresh());
        }
        $this->assertTrue($spent->fresh()->flowIsActive());
        $page = $this->actingAs($spentOwner)->get(route('settings.billing'));
        $page->assertSee('Remove this card? Your free visits are used up, so your code pauses until you add a card again. The amount you owe stays on your account.');
        app(CardOnFileService::class)->removeCard($spent->fresh(), $spentCard->fresh());
        $this->assertFalse($spent->fresh()->flowIsActive());
        $this->assertSame('allowance_exhausted', $spent->fresh()->flowBlockReason());

        // Never-carded-exhausted and removed-card-exhausted are
        // indistinguishable at the gate.
        $never = $this->cardless();
        for ($i = 0; $i < 5; $i++) {
            $this->seedParticipant($never->fresh());
        }
        $this->assertSame(
            $never->fresh()->flowBlockReason(),
            $spent->fresh()->flowBlockReason(),
        );
    }

    // ---------------------------------------------------------------
    // P6: REACTIVATION IS THE SAVE; ZERO SQUARE CALLS IN THE WINDOW
    // ---------------------------------------------------------------

    public function test_p6_reactivation_is_the_save_and_the_window_makes_no_square_calls(): void
    {
        $business = $this->cardless(['square_customer_id' => 'sq_cust_act']);
        [, $qrToken] = World::qr($business);

        // The whole allowance window runs with zero Square calls.
        $membership = $this->referrer($business);
        for ($i = 0; $i < 5; $i++) {
            $this->redeem($this->claimed($this->referrer($business)));
        }
        app(SettlementService::class)->settleAndPersist($business->fresh());
        $this->assertCount(0, $this->gateway->saveCardCalls);
        $this->assertCount(0, $this->gateway->chargeCalls);
        $this->get(route('refer.landing', ['token' => $qrToken]))->assertStatus(404);

        $service = app(CardOnFileService::class);

        // A FAILED save changes nothing: the row lands failed,
        // no default card exists, and the code stays paused.
        $this->gateway->cardFailureCode = 'CARD_DECLINED';
        $failed = $service->saveCard($business->fresh(), 'bad-token');
        $this->assertSame(PaymentMethod::STATE_FAILED, $failed->state);
        $this->gateway->cardFailureCode = null;
        $this->assertNull(PaymentMethod::defaultFor($business->fresh()));
        $this->assertFalse($business->fresh()->flowIsActive());

        // A SUCCEEDED save reactivates at once; the gateway's
        // first call in the business's life is this save's own.
        $callsBefore = count($this->gateway->saveCardCalls);
        $method = $service->saveCard($business->fresh(), 'good-token');
        $this->assertSame(PaymentMethod::STATE_SUCCEEDED, $method->state);
        $this->assertSame($callsBefore + 1, count($this->gateway->saveCardCalls));
        $this->assertTrue($business->fresh()->flowIsActive());
        $this->get(route('refer.landing', ['token' => $qrToken]))->assertOk();

        // Billing runs normally from the 6th counted visit: it
        // settles billable (the 5 free stay free above).
        $this->redeem($this->claimed($this->referrer($business)));
        app(SettlementService::class)->settleAndPersist($business->fresh());
        $coverages = BillingEvent::query()->where('business_id', $business->id)->orderBy('id')->pluck('coverage')->all();
        $this->assertSame('billable', end($coverages));
        $this->assertSame(5, count(array_filter($coverages, fn ($c) => $c === 'free_credit')));
    }

    public function test_p6_unknown_square_outcome_reactivates_nothing_until_adopted(): void
    {
        $business = $this->cardless(['square_customer_id' => 'sq_cust_act2']);
        for ($i = 0; $i < 5; $i++) {
            $this->seedParticipant($business->fresh());
        }
        $this->assertFalse($business->fresh()->flowIsActive());

        // An UNKNOWN outcome (gateway throws mid-save) leaves a
        // pending row and reactivates nothing.
        $this->gateway->saveCardException = new \App\Services\Billing\SquareOutcomeUnknown('timeout');
        try {
            app(CardOnFileService::class)->saveCard($business->fresh(), 'unknown-token');
            $this->fail('an unknown outcome must surface');
        } catch (\App\Services\Billing\SquareOutcomeUnknown) {
        }
        $this->gateway->saveCardException = null;
        $pending = PaymentMethod::query()->where('business_id', $business->id)->first();
        $this->assertNotNull($pending);
        $this->assertSame(PaymentMethod::STATE_PENDING, $pending->state);
        $this->assertFalse($business->fresh()->flowIsActive());

        // The resolver adopting the card is what reactivates.
        $this->gateway->resolvedCards[$pending->idempotency_key] = \App\Services\Billing\CardResult::succeeded('sq_card_adopted', 'VISA', '4242', 12, 2030);
        $outcome = app(CardOnFileService::class)->resolvePendingCard($pending->fresh());
        $this->assertSame('adopted', $outcome);
        $this->assertTrue($business->fresh()->flowIsActive());
    }

    // ---------------------------------------------------------------
    // P7: TWO PAUSES, ONE BUSINESS
    // ---------------------------------------------------------------

    public function test_p7_two_pauses_one_business(): void
    {
        $business = $this->cardless();
        $this->addCard($business);
        for ($i = 0; $i < 5; $i++) {
            $this->seedParticipant($business->fresh());
        }

        // Charge fails: past_due, in grace, flow ON (the card leg).
        $business->forceFill(['billing_status' => 'past_due'])->save();
        BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'state' => BillingRecord::STATE_FAILED,
            'amount_cents' => 200,
            'currency' => 'USD',
            'idempotency_key' => 'p7-failed-charge',
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
        ]);
        // RE-PINNED at the activation merge (2026-10-30): the
        // episode is stated the way the webhook writes it — the
        // billing.past_due audit row. This train's fork derived
        // the grace end from the failed record as a fallback;
        // main's shipped derivation (flow-state train) reads
        // ONLY the audit row and fails closed without it, so a
        // record-only fixture is an undatable episode = paused.
        \App\Models\AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_p7_failed'], $business->fresh(), null);
        $fresh = $business->fresh();
        $this->assertTrue($fresh->flowIsActive());
        $this->assertNull($fresh->flowBlockReason());

        // Remove the card mid-grace: the allowance pause lands
        // IMMEDIATELY, ahead of the grace date, and the status
        // line must not print the grace-date keeps-working line.
        $card = PaymentMethod::defaultFor($fresh);
        app(CardOnFileService::class)->removeCard($fresh, $card->fresh());
        $fresh = $business->fresh();
        $this->assertFalse($fresh->flowIsActive());
        $this->assertSame('allowance_exhausted', $fresh->flowBlockReason());
        $line = BillingCopy::statusLine($fresh);
        $this->assertSame('Your QR code and customer links are paused until you add a card.', $line);
        $this->assertStringNotContainsString('keeps working until', $line);

        // Twin leg: re-add a card (cause 4 clears — the helper
        // also flips billing_status to 'active', so the episode
        // is re-stated), let the grace close unpaid instead:
        // the cause is the dunning pause.
        $this->addCard($business->fresh());
        $business->fresh()->forceFill(['billing_status' => 'past_due'])->save();
        $this->travel(8)->days();
        $fresh = $business->fresh();
        $this->assertFalse($fresh->flowIsActive());
        $this->assertSame('dunning_paused', $fresh->flowBlockReason());
    }

    // ---------------------------------------------------------------
    // P8: FINISH AND CHECKLIST
    // ---------------------------------------------------------------

    public function test_p8_finish_live_and_checklist_counts_its_four_required(): void
    {
        $business = $this->cardless();
        $owner = World::owner($business);

        $finish = $this->actingAs($owner)->get(route('onboarding.finish'));
        $finish->assertOk();
        $finish->assertSee("✓ You're live, {$business->name}.", false);
        $finish->assertSee('Your code is live on your free visits. You have 5 of your 5 free visits left. Add a card any time so it stays on after that.');
        $finish->assertDontSee('Everything is ready except a card on file');

        $checklist = SetupChecklist::for($business->fresh(), $owner);
        [$done, $total] = SetupChecklist::progress($checklist);
        $this->assertSame(4, $total);
        $this->assertArrayHasKey('card_on_file', $checklist);

        // The resume machinery under the RS2-2 merge ruling
        // (final form, 2026-10-30): while required work remains,
        // the target is the first open step in FLOW ORDER —
        // Card here: presented, skippable in one tap, and never
        // a gate (the count below is where "never blocking"
        // lives). With every required step done it returns null
        // even though the card step is undone.
        $states = Onboarding::states($business->fresh(), $owner);
        // Rewards done, card open: resume lands on CARD, the
        // next step in the walk.
        $this->assertSame('card', Onboarding::firstOpenStep($states));
        $allButCard = array_merge($states, [
            'rewards' => true, 'location' => true, 'verify' => true, 'poster' => true, 'test' => true, 'card' => false,
        ]);
        $this->assertNull(Onboarding::firstOpenStep($allButCard));
        $this->assertSame(5, Onboarding::doneCount($allButCard));
        $this->assertSame(5, Onboarding::requiredTotal());

        // The card step remains reachable in flow order and addable.
        $this->actingAs($owner)->get(route('onboarding.step', 'card'))
            ->assertOk()
            ->assertSee('Your code already works. This step keeps it working.')
            ->assertSee('Skip for now')
            ->assertDontSee('This is the step that turns your code on.');
    }

    public function test_p8_finish_paused_form_for_the_burned_before_finish_owner(): void
    {
        $business = $this->cardless(['onboarding_completed_at' => null]);
        $owner = World::owner($business);
        for ($i = 0; $i < 5; $i++) {
            $this->seedParticipant($business->fresh());
        }

        $finish = $this->actingAs($owner)->get(route('onboarding.finish'));
        $finish->assertOk();
        $finish->assertSee('Your code was live, and it is paused now.');
        $finish->assertSee('Your 5 free visits are used up. Add a card to turn your code back on. Nothing is charged when you add your card.');
        $finish->assertSee('Add a card now');
        $finish->assertDontSee("You're live");
    }

    // ---------------------------------------------------------------
    // P9: THE GAMING TEST — "Second shop, same owner" (Section 8)
    // ---------------------------------------------------------------

    public function test_p9_second_shop_same_owner_bound_is_the_identity(): void
    {
        // Business A: cardless, allowance spent, paused.
        $a = $this->cardless();
        for ($i = 0; $i < 5; $i++) {
            $this->seedParticipant($a->fresh());
        }
        $this->assertFalse($a->fresh()->flowIsActive());
        $ownerA = World::owner($a);

        // The same email cannot register a second business.
        $this->post(route('register.store'), [
            'business_name' => 'Second Shop',
            'name' => 'Same Owner',
            'email' => $ownerA->email,
            'phone' => '(718) 555-0199',
            'password' => 'a long phrase nobody else uses 42',
            'password_confirmation' => 'a long phrase nobody else uses 42',
            'country' => 'US', 'street' => '2 Main Street', 'city' => 'New York', 'state' => 'NY', 'postal_code' => '10001',
        ])->assertSessionHasErrors();
        $this->assertFalse(Business::query()->where('name', 'Second Shop')->exists());

        // The same phone cannot register a second business either.
        $this->post(route('register.store'), [
            'business_name' => 'Second Shop',
            'name' => 'Same Owner',
            'email' => 'second.shop@example.test',
            'phone' => $ownerA->phone_e164,
            'password' => 'a long phrase nobody else uses 42',
            'password_confirmation' => 'a long phrase nobody else uses 42',
            'country' => 'US', 'street' => '2 Main Street', 'city' => 'New York', 'state' => 'NY', 'postal_code' => '10001',
        ])->assertSessionHasErrors();
        $this->assertFalse(Business::query()->where('name', 'Second Shop')->exists());

        // A NEW email AND a NEW phone: business B is live on its
        // OWN allowance. The construction permits exactly this —
        // the bound is one allowance per identity, priced in the
        // pass's Section 7.2 — and no platform payout exists in
        // either business (rewards are business-funded; both
        // businesses' events are local rows only).
        $this->post(route('register.store'), [
            'business_name' => 'Second Shop',
            'name' => 'Same Owner',
            'email' => 'second.shop@example.test',
            'phone' => '(718) 555-0199',
            'password' => 'a long phrase nobody else uses 42',
            'password_confirmation' => 'a long phrase nobody else uses 42',
            'country' => 'US', 'street' => '2 Main Street', 'city' => 'New York', 'state' => 'NY', 'postal_code' => '10001',
        ]);
        $b = Business::query()->where('name', 'Second Shop')->firstOrFail();
        $b->forceFill(['friend_reward_text' => '$10 off your first visit'])->save();
        $this->assertTrue($b->fresh()->flowIsActive());
        $this->assertSame(5, $b->fresh()->allowanceLeft());
        $this->assertCount(0, $this->gateway->chargeCalls);
    }

    // ---------------------------------------------------------------
    // P10: WELCOME NUDGE TRUTH under the new count
    // ---------------------------------------------------------------

    public function test_p10_welcome_nudge_counts_under_the_new_model(): void
    {
        // (a) Unverified, reward set, no card, allowance left:
        // ZERO steps left — the code works — and verification is
        // still its own sentence.
        $a = User::factory()->unverified()->create();
        $a->business->forceFill(['friend_reward_text' => 'Free coffee'])->save();
        $mailA = new WelcomeMail($a->fresh());
        $this->assertSame('Welcome to Easy Refer', $mailA->envelope()->subject);
        $htmlA = $mailA->render();
        $this->assertStringContainsString('No steps left before your code works.', $htmlA);
        $this->assertStringContainsString('Verify this email address so your receipts and password resets reach you.', $htmlA);

        // (b) Unverified, reward unset, no card: ONE step, the
        // reward (the card is not a step while allowance lasts).
        $b = User::factory()->unverified()->create();
        $mailB = new WelcomeMail($b->fresh());
        $this->assertSame('Welcome to Easy Refer: one step left before your code works', $mailB->envelope()->subject);
        $this->assertStringContainsString('One step left before your code works: set your rewards.', $mailB->render());

        // (c) Verified, reward set, no card, allowance EXHAUSTED:
        // the card step, once.
        $c = User::factory()->create();
        $c->business->forceFill(['friend_reward_text' => 'Free coffee'])->save();
        for ($i = 0; $i < 5; $i++) {
            $this->seedParticipant($c->business->fresh());
        }
        $mailC = new WelcomeMail($c->fresh());
        $this->assertSame('Welcome to Easy Refer: one step left before your code works', $mailC->envelope()->subject);
        $htmlC = $mailC->render();
        $this->assertStringContainsString('One step left before your code works: add your card.', $htmlC);
        $this->assertStringContainsString('Add your card', $htmlC);

        // (d) Verified, reward set, no card, allowance left: the
        // confirmed branch says the code is ready and points at
        // the QR page, not a card form.
        $d = User::factory()->create();
        $d->business->forceFill(['friend_reward_text' => 'Free coffee'])->save();
        $mailD = new WelcomeMail($d->fresh());
        $this->assertSame('Welcome to Easy Refer', $mailD->envelope()->subject);
        $htmlD = $mailD->render();
        $this->assertStringContainsString('your code is ready to go out', $htmlD);
        $this->assertStringContainsString('Get your QR code', $htmlD);
        $this->assertStringNotContainsString('One step left before your code works: add your card.', $htmlD);
    }
}
