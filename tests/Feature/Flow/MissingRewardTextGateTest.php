<?php

namespace Tests\Feature\Flow;

use App\Contracts\PhoneLookupGateway;
use App\Contracts\PhoneVerificationGateway;
use App\Contracts\SmsGateway;
use App\Mail\WelcomeMail;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakePhoneLookupGateway;
use Tests\Fakes\FakePhoneVerificationGateway;
use Tests\Fakes\FakeSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Train F2 (finding F-2, r41 re-check check 10; design pass
 * hidden_files/night-loop/f2-redemption-500-design-pass.md):
 * a business that saved a card before setting reward texts used
 * to be fully live on the card alone, and the staff redemption
 * confirm then 500'd on the NOT NULL rewards.reward_text_snapshot
 * (both texts NULL), rolling back the whole visit.
 *
 * The decided shape: (b) activation gate — flowIsActive()
 * requires a trimmed, non-empty friend reward text; (c) a
 * redemption backstop — a blank snapshot returns the designed
 * missing_reward_text outcome with one audit event and zero
 * other writes; plus snapshot normalization (trimmed). Copy:
 * Amendment 40 (F2-GATE-01, F2-FIN-01, F2-BILL-01/02,
 * F2-RDM-01, F2-WEL-01, FAQ-07 v2, pricing bullet v2,
 * onboarding skip line v2).
 */
class MissingRewardTextGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        $this->app->instance(PhoneVerificationGateway::class, new FakePhoneVerificationGateway);
        $this->app->instance(PhoneLookupGateway::class, new FakePhoneLookupGateway);
        Mail::fake();
    }

    private function textlessCardBusiness(array $overrides = []): Business
    {
        return World::business(array_merge([
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
        ], $overrides));
    }

    /** @return array{0: Referral, 1: string} */
    private function pendingReferral(Business $business, string $suffix): array
    {
        $referrer = World::customer('+121255501'.$suffix, 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+121255502'.$suffix, 'Fri', 'End');

        return World::referral($membership, $friend);
    }

    private function staffNonce(string $token): string
    {
        $response = $this->get(route('redeem.show', ['token' => $token]))->assertOk();
        preg_match('/name="nonce" value="([^"]+)"/', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'staff screen carries a nonce');

        return $m[1];
    }

    private function blockedMessage(Business $business): string
    {
        return "This visit cannot be finished yet. {$business->name} has not set its reward. The visit is still saved. Ask the owner to set the reward in Settings, then scan again.";
    }

    // ----------------------------------------------------------------
    // Test 1 (the repro): the r41 chain, post-fix behavior.
    // Pre-fix this fails: the confirm POST 500s on the NOT NULL
    // snapshot (and the gate assertions fail earlier still).
    // ----------------------------------------------------------------
    public function test_repro_f2_card_only_business_never_500s_and_the_visit_is_preserved(): void
    {
        $business = $this->textlessCardBusiness();

        // The gate: the flow is off, so a scan answers with the
        // designed not_active page, byte-identical in kind to a
        // cardless business's answer.
        $this->assertFalse($business->flowIsActive());
        [, $qrToken] = World::qr($business);
        $this->get(route('refer.landing', ['token' => $qrToken]))
            ->assertNotFound()
            ->assertSee('This code is not active')
            ->assertSee('Please ask the business for help');

        // The backstop seam: a pending referral that already
        // exists in the state (created pre-gate, e.g. in flight
        // at deploy). The staff screen names the block BEFORE
        // the press and renders no confirm form.
        [$referral, $token] = $this->pendingReferral($business, '01');

        $this->get(route('redeem.show', ['token' => $token]))
            ->assertOk()
            ->assertSee($this->blockedMessage($business))
            ->assertDontSee('name="nonce"', false)
            ->assertDontSee('Mark as Redeemed');

        // A press that races the un-set (nonce issued while texts
        // were set, business un-set before the POST) lands on the
        // designed answer: no 500, referral still pending, zero
        // billing events, zero rewards, exactly one blocked event.
        $business->forceFill([
            'friend_reward_text' => '$10 off your first visit',
            'referrer_reward_text' => '$10 off',
        ])->save();
        $nonce = $this->staffNonce($token);
        $business->forceFill([
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
        ])->save();

        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $nonce])
            ->assertRedirect(route('redeem.show', ['token' => $token]))
            ->assertSessionHas('staff_error', $this->blockedMessage($business));

        $referral->refresh();
        $this->assertSame(Referral::STATE_PENDING, $referral->state);
        $this->assertNull($referral->redeemed_at);
        $this->assertSame(0, BillingEvent::count());
        $this->assertSame(0, Reward::count());
        $this->assertSame(1, ReferralEvent::query()
            ->where('event_type', 'redemption_blocked_missing_reward_text')
            ->count());
    }

    // Test 2: gate unit pins.
    public function test_gate_unit_pins(): void
    {
        $this->assertFalse($this->textlessCardBusiness()->flowIsActive());
        $this->assertTrue(World::business()->flowIsActive());
        // Referrer text optional (OD-2 fallback).
        $this->assertTrue(World::business(['referrer_reward_text' => null])->flowIsActive());
        // Whitespace-only friend text counts as unset.
        $this->assertFalse(World::business(['friend_reward_text' => '   '])->flowIsActive());
        // Past-due in grace: textless is off, texts-set stays live.
        $this->assertFalse($this->textlessCardBusiness(['billing_status' => 'past_due'])->flowIsActive());
        // FLOW-STATE TRUTH train: "in grace" now requires a
        // datable episode (a bare past_due with no evidence
        // fails closed to paused). Two-key Finding 1 fix
        // (2026-10-30): the episode evidence is the
        // billing.past_due audit row — the failed record alone
        // is not episode-bound and no longer dates anything —
        // so the fixture writes the real webhook shape.
        $inGrace = World::business(['billing_status' => 'past_due']);
        $inGraceRecord = \App\Models\BillingRecord::create([
            'business_id' => $inGrace->id,
            'kind' => \App\Models\BillingRecord::KIND_PER_REFERRAL_BATCH,
            'state' => \App\Models\BillingRecord::STATE_FAILED,
            'amount_cents' => 1800,
            'currency' => 'USD',
            'idempotency_key' => 'f2-gate-ingrace-fixture',
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
        ]);
        \App\Models\AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_f2_ingrace'], $inGrace, $inGraceRecord);
        $this->assertTrue($inGrace->fresh()->flowIsActive());
        // The predicate itself.
        $this->assertTrue(World::business()->hasFriendRewardText());
        $this->assertFalse(World::business(['friend_reward_text' => '  '])->hasFriendRewardText());
        $this->assertFalse(World::business(['friend_reward_text' => null])->hasFriendRewardText());
    }

    // Test 3: public gates are indistinguishable across causes.
    // RE-DERIVED (activation redesign, 2026-10-30): the old
    // comparator was a cardless business, which under the
    // allowance model is LIVE (its landing is the flow itself),
    // so the comparator is now a business inactive for a
    // different non-allowance cause — past-due past grace,
    // texts set. Both causes render the same not_active page;
    // the allowance cause is the one cause that splits off
    // (ActivationAllowanceTest P4 pins its own page).
    public function test_public_surfaces_answer_not_active_without_naming_the_reason(): void
    {
        $textless = $this->textlessCardBusiness();
        [, $qrToken] = World::qr($textless);
        $cardless = World::business(['billing_status' => 'past_due']);
        $failedRecord = \App\Models\BillingRecord::create([
            'business_id' => $cardless->id,
            'kind' => \App\Models\BillingRecord::KIND_PER_REFERRAL_BATCH,
            'state' => \App\Models\BillingRecord::STATE_FAILED,
            'amount_cents' => 200,
            'currency' => 'USD',
            'idempotency_key' => 'f2-test3-failed',
            'period_start' => now()->subDays(40)->startOfMonth(),
            'period_end' => now()->subDays(40)->endOfMonth(),
        ]);
        // The episode is 10 days old: the 7-day grace has closed.
        $failedRecord->forceFill(['created_at' => now()->subDays(10)])->save();
        [, $cardlessToken] = World::qr($cardless);

        $a = $this->get(route('refer.landing', ['token' => $qrToken]));
        $b = $this->get(route('refer.landing', ['token' => $cardlessToken]));
        $a->assertNotFound()->assertSee('This code is not active');
        $b->assertNotFound()->assertSee('This code is not active');
        // No public string distinguishes the missing step.
        $a->assertDontSee('reward');
        $this->assertSame(
            str_replace($textless->name, 'X', $a->getContent()),
            str_replace($cardless->name, 'X', $b->getContent()),
        );

        $this->get(route('join.show', ['slug' => $textless->ensureJoinSlug()]))
            ->assertRedirect(route('refer.landing', ['token' => $qrToken]));

        // Enroll POST is gated too (valid payload; the gate
        // answers after validation, before any enrollment).
        $this->post(route('refer.enroll', ['token' => $qrToken]), [
            'first_name' => 'Fri',
            'last_name' => 'End',
            'phone' => '(212) 555-0177',
            'consent' => '1',
        ])
            ->assertNotFound()
            ->assertSee('This code is not active');

        // Friend landing for an existing member's link is gated.
        $referrer = World::customer('+12125550301', 'Reg', 'Ular');
        [, $shareToken] = World::membership($textless, $referrer);
        $this->get(route('refer.friend', ['token' => $shareToken]))
            ->assertNotFound()
            ->assertSee('This code is not active');
    }

    // Test 4: in-flight preservation — set texts, same token completes once.
    public function test_pending_referral_completes_once_texts_are_set(): void
    {
        $business = $this->textlessCardBusiness();
        [$referral, $token] = $this->pendingReferral($business, '02');

        $owner = World::owner($business);
        $this->actingAs($owner)->post(route('settings.rewards.update'), [
            'friend_reward_text' => 'Free coffee',
            'referrer_reward_text' => '',
            'redemption_window_days' => 30,
            'reward_vesting_days' => 14,
        ])->assertRedirect();

        $this->assertTrue($business->fresh()->flowIsActive());

        $nonce = $this->staffNonce($token);
        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $nonce])
            ->assertRedirect(route('redeem.show', ['token' => $token]));

        $this->assertSame(Referral::STATE_REDEEMED, $referral->fresh()->state);
        $this->assertSame(1, BillingEvent::count());
        $reward = Reward::query()->firstOrFail();
        $this->assertSame('Free coffee', $reward->reward_text_snapshot);
    }

    // Test 5: snapshot normalization.
    public function test_snapshot_is_trimmed_and_never_blank(): void
    {
        $business = World::business([
            'referrer_reward_text' => '  $10 off  ',
            'friend_reward_text' => '$10 off your first visit',
        ]);
        [, $token] = $this->pendingReferral($business, '03');
        $nonce = $this->staffNonce($token);
        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $nonce])->assertRedirect();
        $this->assertSame('$10 off', Reward::query()->firstOrFail()->reward_text_snapshot);

        // Whitespace-only referrer falls back to the friend text.
        $business2 = World::business([
            'referrer_reward_text' => '   ',
            'friend_reward_text' => 'Free coffee',
        ]);
        [, $token2] = $this->pendingReferral($business2, '04');
        $nonce2 = $this->staffNonce($token2);
        $this->post(route('redeem.confirm', ['token' => $token2]), ['nonce' => $nonce2])->assertRedirect();
        $this->assertSame('Free coffee', Reward::query()
            ->where('business_id', $business2->id)->firstOrFail()->reward_text_snapshot);
    }

    // Test 6: minted rewards stay claimable after an un-set.
    public function test_minted_reward_claims_survive_an_unset(): void
    {
        $business = World::business();
        $referrer = World::customer('+12125550501', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550502', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        [$reward, $claimToken] = World::reward($membership, $referral);

        $business->forceFill(['friend_reward_text' => null, 'referrer_reward_text' => null])->save();
        $this->assertFalse($business->fresh()->flowIsActive());

        $this->get(route('refer.claim.page', ['token' => $claimToken]))
            ->assertOk()
            ->assertSee('$10 off');

        $nonce = $this->staffNonce($claimToken);
        $this->post(route('redeem.confirm', ['token' => $claimToken]), ['nonce' => $nonce])
            ->assertRedirect(route('redeem.show', ['token' => $claimToken]));
        $this->assertSame(Reward::STATE_CLAIMED, $reward->fresh()->state);
    }

    // Test 7: owner-surface branches.
    public function test_owner_surfaces_name_rewards_not_the_card_for_a_textless_card_business(): void
    {
        $business = $this->textlessCardBusiness();
        $owner = World::owner($business);

        $gate = 'This code is not active yet. Set your friend reward to turn it on.';

        $this->actingAs($owner)->get(route('owner.qr'))
            ->assertOk()
            ->assertSee($gate)
            ->assertSee('Set your rewards')
            ->assertDontSee('Add a card to turn it on');

        $this->actingAs($owner)->get(route('campaigns.index'))
            ->assertOk()
            ->assertSee('Set your rewards')
            ->assertDontSee('Add a card');

        // Texts set, no card: RE-DERIVED (activation redesign,
        // 2026-10-30) — this business is LIVE on its allowance,
        // so no inactive notice renders on the QR page at all
        // and campaigns are open. The card branch returns only
        // in the allowance-exhausted state
        // (ActivationAllowanceTest P4/P8 pin its surfaces).
        $noCard = World::business(['billing_status' => 'none']);
        $noCardOwner = World::owner($noCard);
        $this->actingAs($noCardOwner)->get(route('owner.qr'))
            ->assertOk()
            ->assertDontSee('Add a card to turn it on')
            ->assertDontSee('Set your friend reward to turn it on')
            ->assertDontSee('This code is not active yet.');
        // The campaigns panel may still render in this test
        // environment for the platform-gateway reason (nothing
        // is asked of the owner then); what must NOT render is
        // the flow branch — no program-inactive line, no card ask.
        $this->actingAs($noCardOwner)->get(route('campaigns.index'))
            ->assertOk()
            ->assertDontSee('Campaigns open once your program is active.')
            ->assertDontSee('Add a card');

        // Onboarding finish: rewards branch takes precedence.
        $this->actingAs($owner)->get(route('onboarding.finish'))
            ->assertOk()
            ->assertSee("You're set up, one step from live.", false)
            ->assertSee('Everything is ready except your rewards.')
            ->assertSee('Set your rewards')
            ->assertDontSee("You're live", false);

        $this->actingAs($owner)->post(route('onboarding.complete'), ['door' => 'rewards'])
            ->assertRedirect(route('settings.rewards'));

        // Finish for texts-set/no-card is LIVE under the
        // allowance model (the retired card branch's copy is
        // gone with its premise, census C-09); live keeps live copy.
        $this->actingAs($noCardOwner)->get(route('onboarding.finish'))
            ->assertOk()
            ->assertSee("You're live", false)
            ->assertDontSee('Everything is ready except a card on file.');
        $live = World::business();
        $this->actingAs(World::owner($live))->get(route('onboarding.finish'))
            ->assertOk()
            ->assertSee("You're live", false);

        // Billing page: the gate well only in card+textless.
        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_f2',
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
        ]);
        $this->actingAs($owner)->get(route('settings.billing'))
            ->assertOk()
            ->assertSee('Your card is saved. Your code turns on once you set your friend reward.')
            ->assertSee('Set your rewards');
        $this->actingAs(World::owner($live))->get(route('settings.billing'))
            ->assertOk()
            ->assertDontSee('Your code turns on once you set your friend reward');
    }

    // Test 8: Undo re-entry (abuse acceptance).
    public function test_undo_of_the_first_rewards_save_deactivates_and_backstops(): void
    {
        $business = $this->textlessCardBusiness();
        $owner = World::owner($business);
        [$referral, $token] = $this->pendingReferral($business, '06');

        $this->actingAs($owner)->post(route('settings.rewards.update'), [
            'friend_reward_text' => 'Free coffee',
            'referrer_reward_text' => '$5 off',
            'redemption_window_days' => 30,
            'reward_vesting_days' => 14,
        ])->assertRedirect();
        $this->assertTrue($business->fresh()->flowIsActive());

        $undoToken = session('toast')['undo']['token'];
        $this->actingAs($owner)->post(route('actions.undo'), ['undo_token' => $undoToken])
            ->assertRedirect();

        $business->refresh();
        $this->assertNull($business->friend_reward_text);
        $this->assertFalse($business->flowIsActive());

        // The in-flight referral is preserved and backstopped.
        $this->get(route('redeem.show', ['token' => $token]))
            ->assertOk()
            ->assertSee($this->blockedMessage($business))
            ->assertDontSee('Mark as Redeemed');
        $this->assertSame(Referral::STATE_PENDING, $referral->fresh()->state);
        $this->assertSame(0, BillingEvent::count());
    }

    // Test 9: welcome mail split.
    public function test_welcome_mail_textless_branch(): void
    {
        $user = User::factory()->create();
        $user->business->forceFill([
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
        ])->save();
        PaymentMethod::create([
            'business_id' => $user->business->id,
            'square_card_id' => 'card_f2_wel',
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
        ]);

        $html = (new WelcomeMail($user))->render();
        $this->assertStringContainsString(
            'Your email address is confirmed. One step left before your code works: set your rewards.',
            $html,
        );
        $this->assertStringContainsString('Set your rewards', $html);
        $this->assertStringContainsString(route('settings.rewards'), $html);
        $this->assertStringNotContainsString('your code is ready to go out', $html);

        // Texts set: the A2 branch verbatim.
        $user->business->forceFill(['friend_reward_text' => 'Free coffee'])->save();
        $html = (new WelcomeMail($user))->render();
        $this->assertStringContainsString('your code is ready to go out', $html);
        $this->assertStringContainsString('Get your QR code', $html);
    }

    // Test 10: double completion after a block mints exactly once.
    public function test_blocked_referral_completes_exactly_once_after_texts_are_set(): void
    {
        $business = $this->textlessCardBusiness();
        [$referral, $token] = $this->pendingReferral($business, '07');

        $business->forceFill(['friend_reward_text' => 'Free coffee'])->save();

        // Two staff renders, two live nonces, then both press.
        $first = $this->staffNonce($token);
        $second = $this->staffNonce($token);
        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $first])->assertRedirect();
        $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $second])->assertRedirect();

        $this->assertSame(Referral::STATE_REDEEMED, $referral->fresh()->state);
        $this->assertSame(1, BillingEvent::count());
        $this->assertSame(1, Reward::count());
    }

    // Test 11: absence sweep + public copy v2.
    public function test_absence_sweep_and_public_copy_v2(): void
    {
        $business = $this->textlessCardBusiness();
        $owner = World::owner($business);
        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_f2_abs',
            'card_brand' => 'Visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
        ]);

        foreach ([route('dashboard'), route('owner.qr'), route('onboarding.finish'), route('settings.billing')] as $url) {
            $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();
            foreach (["You're live", 'Your code works', 'Your program is live', 'ready to go out', 'Add a card to turn it on'] as $banned) {
                $this->assertStringNotContainsString($banned, $html, "{$banned} leaked on {$url}");
            }
        }

        // RE-DERIVED (activation redesign, census C-33/C-38):
        // FAQ-07 and the pricing bullet state the allowance
        // model — the reward activates, the first 5 visits are
        // free with no card, a card keeps the code going.
        $faq = $this->get(route('faq'))->assertOk()->getContent();
        $this->assertStringNotContainsString('turns on once a card is on file.', $faq);
        $this->assertStringContainsString('turns on once your friend reward is set, and your first 5 visits are free with no card at all', $faq);

        $pricing = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('Your friend reward set to activate, and your first 5 visits are free. A card keeps your code going after that', $pricing);
        $this->assertStringNotContainsString('A card on file and your friend reward set to activate', $pricing);
    }

    // Test 12 (F-2 ADDENDUM, coordinator ruling on the build
    // report's §2 flag): the Action Center setup row's trailing
    // clause (ActionCenter::setupChecklist) names the actually
    // missing activation prerequisite, branched in the
    // F2-GATE-01 pattern (Amendment 40, F2-GATE-02): the
    // rewards clause takes precedence whenever the friend
    // reward text is blank, in any card state; the card clause
    // renders only when the texts are set and the card leg is
    // the blocker; no clause renders when the flow is live or
    // the account itself is not active.
    public function test_action_center_setup_line_names_the_missing_prerequisite(): void
    {
        $cardClause = 'Your QR code is not active until you add a card.';
        $rewardsClause = 'Your QR code is not active until you set your friend reward.';

        // World's owner is created unverified (the column is
        // not mass-assignable); verify so the email step counts
        // the way the coherence fixtures count it.
        $verifiedOwner = function (Business $business) {
            $owner = World::owner($business);
            $owner->forceFill(['email_verified_at' => now()])->save();

            return $owner;
        };

        $succeededCard = function (Business $business, string $cardId): void {
            PaymentMethod::create([
                'business_id' => $business->id,
                'square_card_id' => $cardId,
                'card_brand' => 'Visa',
                'last4' => '4242',
                'exp_month' => 12,
                'exp_year' => 2030,
                'is_default' => true,
                'state' => PaymentMethod::STATE_SUCCEEDED,
            ]);
        };

        // Card present, friend reward text missing (the F-2
        // state): the rewards clause, never the card clause.
        // Required done (activation redesign, census C-24: the
        // card is optional, required is 4): email = 1 of 4.
        $carded = $this->textlessCardBusiness();
        $succeededCard($carded, 'card_f2_ac1');
        $this->actingAs($verifiedOwner($carded))->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Your setup is not finished: 1 of 4 required steps done. '.$rewardsClause)
            ->assertDontSee($cardClause);

        // Both missing: rewards precedence, the F2-GATE-01
        // order (rewards before card in setup order).
        // Required done: email only = 1 of 4.
        $bothMissing = World::business([
            'billing_status' => 'none',
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
        ]);
        $this->actingAs($verifiedOwner($bothMissing))->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Your setup is not finished: 1 of 4 required steps done. '.$rewardsClause)
            ->assertDontSee($cardClause);

        // No card, texts set, allowance untouched: the code is
        // LIVE, so NO clause renders (the card clause's valid
        // states re-derived, census addendum C-37).
        // Required done: email + rewards = 2 of 4.
        $noCard = World::business(['billing_status' => 'none']);
        $this->actingAs($verifiedOwner($noCard))->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Your setup is not finished: 2 of 4 required steps done.')
            ->assertDontSee('Your QR code is not active until');

        // No card, texts set, allowance EXHAUSTED: the card
        // clause returns — it is the actual block reason.
        $exhausted = World::business(['billing_status' => 'none']);
        $exhMembershipOwner = World::owner($exhausted);
        $exhReferrer = World::customer('+12125550401', 'Reg', 'Ular');
        [$exhMembership] = World::membership($exhausted, $exhReferrer);
        for ($i = 0; $i < 5; $i++) {
            $exhFriend = World::customer('+1212555041'.(string) $i, 'Fri', 'End');
            [$exhReferral] = World::referral($exhMembership, $exhFriend);
            \App\Models\BillingEvent::create([
                'business_id' => $exhausted->id,
                'referral_id' => $exhReferral->id,
                'amount_cents' => 200,
                'coverage' => 'billable',
            ]);
        }
        $this->actingAs($verifiedOwner($exhausted))->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Your setup is not finished: 2 of 4 required steps done. '.$cardClause)
            ->assertDontSee($rewardsClause);

        // Card + texts (flow live) with setup still open
        // (poster + test steps): no not-active clause at all.
        // Required done: email + rewards = 2 of 4.
        $live = World::business();
        $succeededCard($live, 'card_f2_ac2');
        $this->actingAs($verifiedOwner($live))->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Your setup is not finished: 2 of 4 required steps done.')
            ->assertDontSee('Your QR code is not active until');

        // Past due in grace, card present, text missing: the
        // rewards clause (the past-due row on the same page
        // carries the billing truth separately).
        $grace = $this->textlessCardBusiness(['billing_status' => 'past_due']);
        $succeededCard($grace, 'card_f2_ac3');
        $this->actingAs($verifiedOwner($grace))->get(route('actions.index'))
            ->assertOk()
            ->assertSee($rewardsClause)
            ->assertDontSee($cardClause);

        // Account itself not active (disabled): words only —
        // no owner-action clause, mirroring QR-INACTIVE-01b.
        $disabled = World::business([
            'status' => 'disabled',
            'billing_status' => 'none',
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
        ]);
        $this->actingAs($verifiedOwner($disabled))->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Your setup is not finished: 1 of 4 required steps done.')
            ->assertDontSee('Your QR code is not active until');
    }

    // ----------------------------------------------------------------
    // Test 13 (two-key review condition 3, binding): the
    // onboarding skip line v2 (Amendment 40) is recorded VALID
    // ONLY IN the onboarding rewards step while the friend
    // reward text is blank. Both directions pinned: present on
    // a blank-text render, absent on a texts-set revisit (where
    // skipping changes nothing and a reward is already set).
    // ----------------------------------------------------------------
    public function test_onboarding_skip_line_renders_only_while_the_friend_reward_text_is_blank(): void
    {
        $skipLine = 'Skipping leaves your code off until you set a reward: customers cannot join yet.';

        // Blank friend reward text: the line renders.
        $blank = $this->textlessCardBusiness();
        $this->actingAs(World::owner($blank))->get(route('onboarding.step', 'rewards'))
            ->assertOk()
            ->assertSee($skipLine);

        // Friend reward text already set (a revisit): the line
        // must not render; the done panel owns this state.
        $set = World::business();
        $this->assertTrue($set->hasFriendRewardText());
        $this->actingAs(World::owner($set))->get(route('onboarding.step', 'rewards'))
            ->assertOk()
            ->assertSee('Reward set.')
            ->assertDontSee($skipLine);
    }
}
