<?php

namespace Tests\Feature\Owner;

use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Support\SetupChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Action Center coherence (Phase 2; hidden_files/
 * action-center-coherence-audit.md Sections A + C, with the
 * coordinator's Q2/Q3 rulings). The audit's empirical
 * S-scenarios, as named acceptance tests: every surface must
 * tell the same truth about the same state — done means the
 * outcome holds (G2), the blocker is named where the owner
 * acts (G1), dismissal never manufactures health (G3), a lost
 * card is its own row (G4), one state is one row (G5), and
 * the two setup dismissals are one domain keyed on the open
 * steps' composition (G6/G7).
 */
class ActionCenterCoherenceTest extends TestCase
{
    use RefreshDatabase;

    private function baseBusiness(array $overrides = [])
    {
        return World::business(array_merge([
            'billing_status' => 'none',
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
            'google_review_url' => null,
            'square_customer_id' => null,
        ], $overrides));
    }

    /** World's owner user is created unverified (the column is
     *  not mass-assignable), so pin verification the way the
     *  shipped suites pin it. */
    private function verifiedOwner($business)
    {
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();

        return $owner;
    }

    private function posterEvent($business): void
    {
        ReferralEvent::create(['business_id' => $business->id, 'event_type' => 'poster_downloaded', 'actor' => 'owner']);
    }

    private function selfMembership($business, $owner): void
    {
        World::membership($business, World::customer($owner->phone_e164));
    }

    private function succeededCard($business): PaymentMethod
    {
        return PaymentMethod::create([
            'business_id' => $business->id, 'square_card_id' => 'card_1', 'card_brand' => 'Visa',
            'last4' => '4242', 'exp_month' => 12, 'exp_year' => (int) now()->addYears(3)->format('Y'),
            'is_default' => true, 'state' => PaymentMethod::STATE_SUCCEEDED,
        ]);
    }

    public function test_s6_failed_card_save_reads_as_cardless_on_its_allowance(): void
    {
        // The Square customer record exists (stored at the
        // START of saveCard) but the card itself failed.
        //
        // RE-DERIVED (activation redesign, census C-24/C-25,
        // 2026-10-30): this test's old premise — billing never
        // activated, the flow is dead, the setup row names the
        // card — was the pre-allowance gate. Under the new rule
        // a failed save is simply never-carded: the card step
        // reads the outcome, not the proxy (still false here),
        // but the card is OPTIONAL, so setup completes without
        // it (4 of 4), the code is live on the free-visit
        // allowance, and the missing card surfaces as the
        // no-card row (ACT-AC-01a) — never as silence, and
        // never as "good standing".
        $business = $this->baseBusiness([
            'billing_status' => 'none', 'square_customer_id' => 'sq_orphan',
            'friend_reward_text' => '$10 off your first visit', 'referrer_reward_text' => '$10 off',
            'google_review_url' => 'https://www.google.com/maps/place/example',
        ]);
        $owner = $this->verifiedOwner($business);
        $this->posterEvent($business);
        $this->selfMembership($business, $owner);
        PaymentMethod::create([
            'business_id' => $business->id, 'square_card_id' => 'card_bad', 'card_brand' => 'Visa',
            'last4' => '0002', 'exp_month' => 12, 'exp_year' => (int) now()->addYears(3)->format('Y'),
            'is_default' => false, 'state' => PaymentMethod::STATE_FAILED,
        ]);
        [, $plain] = World::qr($business);

        $checklist = SetupChecklist::for($business->fresh(), $owner);
        $this->assertFalse($checklist['card_on_file']);
        $this->assertSame([4, 4], SetupChecklist::progress($checklist));

        // The dashboard carries the open item as its badge
        // (item lines live on the Action Center itself) and no
        // longer shows a steps count: required setup is done.
        $dashboard = $this->actingAs($owner)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertSee('data-action-center-badge', false);
        $dashboard->assertSee('>1</span>', false);
        $dashboard->assertDontSee('required steps done');

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertDontSee('Account in good standing')
            ->assertDontSee('Your setup is not finished')
            ->assertSee('You have no card on file. You have 5 of your 5 free visits left, and your code stays on while they last.');

        // Live on the allowance despite the failed save.
        $this->get(route('refer.landing', ['token' => $plain]))
            ->assertOk();
    }

    public function test_s4_genuinely_healthy_state_still_reads_healthy(): void
    {
        $business = $this->baseBusiness([
            'billing_status' => 'active', 'square_customer_id' => 'sq_1',
            'friend_reward_text' => '$10 off your first visit', 'referrer_reward_text' => '$10 off',
            'google_review_url' => 'https://www.google.com/maps/place/example',
        ]);
        $owner = $this->verifiedOwner($business);
        $this->posterEvent($business);
        $this->selfMembership($business, $owner);
        $this->succeededCard($business);
        [, $plain] = World::qr($business);

        // RE-DERIVED (activation redesign, census C-24): the
        // required set is 4 (the card is optional); a fully
        // healthy business completes all 4.
        $this->assertSame([4, 4], SetupChecklist::progress(SetupChecklist::for($business->fresh(), $owner)));

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertSee('✓ Account in good standing')
            ->assertDontSee('data-action-center-badge', false);

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Setup checklist');

        // A live flow carries no inactive notice anywhere.
        $this->actingAs($owner)->get(route('owner.qr'))
            ->assertOk()
            ->assertDontSee('This code is not active');

        $this->get(route('refer.landing', ['token' => $plain]))->assertOk();
    }

    public function test_s1_brand_new_owner_sees_the_not_live_story_everywhere(): void
    {
        $business = $this->baseBusiness();
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your program is not live yet. Finish setup and your code starts working.')
            ->assertDontSee('Your program is live.');

        // Amendment 40 addendum (F2-GATE-02): this fixture has
        // neither rewards nor a card; the Action Center setup
        // line now branches in the same F2-GATE-01 pattern and
        // names the first missing step in setup order (rewards
        // before card), exactly like the QR notice below. (The
        // F-2 build left this line untouched for a coordinator
        // ruling; the ruling brought it into scope.)
        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Your setup is not finished: 0 of 4 required steps done. Your QR code is not active until you set your friend reward.')
            ->assertDontSee('Your QR code is not active until you add a card.');
        $this->actingAs($owner)->get(route('owner.qr'))
            ->assertOk()
            // FLOW-STATE TRUTH train: both gates are open in
            // this fixture, so the notice names both missing
            // steps (the reward-only turns-on line was the
            // A-D1 class on this surface).
            ->assertSee('This code is not active yet. Set your friend reward and add a card to turn it on.')
            ->assertSee('href="'.route('settings.rewards').'"', false);

        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'counter']))
            ->assertOk()
            ->assertSee('This code is not active yet. Set your friend reward and add a card to turn it on.');
    }

    public function test_s2a_owner_state_names_the_card_as_the_blocker(): void
    {
        // The owner's own reconstruction: email verified, the
        // poster taken, self-joined — 3 of 4 required — and no
        // card; the code he printed answers customers with a
        // 404 because the REWARD is unset (the card alone never
        // blocks under the allowance model).
        $business = $this->baseBusiness();
        $owner = $this->verifiedOwner($business);
        $this->posterEvent($business);
        $this->selfMembership($business, $owner);
        [, $plain] = World::qr($business);

        // Amendment 40 addendum (F2-GATE-02): rewards are unset
        // in this state, and the Action Center setup line names
        // them first (setup order), in the same F2-GATE-01
        // pattern as the QR notice below. This test's name is
        // historical: the card is no longer the named blocker
        // in the both-missing state (the card clause survives
        // for the texts-set/no-card state, pinned by S6).
        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Your setup is not finished: 3 of 4 required steps done. Your QR code is not active until you set your friend reward.')
            ->assertDontSee('Your QR code is not active until you add a card.');
        $this->actingAs($owner)->get(route('owner.qr'))
            ->assertOk()
            // FLOW-STATE TRUTH train: rewards unset AND no
            // card in this state too — the notice names both.
            ->assertSee('This code is not active yet. Set your friend reward and add a card to turn it on.');

        $this->get(route('refer.landing', ['token' => $plain]))
            ->assertNotFound()
            ->assertSee('This code is not active');
    }

    public function test_s2b_paused_account_renders_the_no_action_inactive_notice(): void
    {
        // QR-INACTIVE-01b (Amendment 24): the account itself
        // is not active (admin-paused = status 'disabled'), so
        // no owner action on these surfaces resolves it — the
        // notice is words only: the 01b sentence verbatim, the
        // 01a billing form absent, and no "Add a card" link.
        $business = $this->baseBusiness(['status' => 'disabled']);
        $owner = $this->verifiedOwner($business);

        $this->actingAs($owner)->get(route('owner.qr'))
            ->assertOk()
            ->assertSee('This code is not active right now, so new customers cannot join.')
            ->assertDontSee('This code is not active yet.')
            ->assertDontSee('Add a card');

        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'counter']))
            ->assertOk()
            ->assertSee('This code is not active right now, so new customers cannot join.')
            ->assertDontSee('Add a card');
    }

    public function test_s9_past_due_row_cannot_be_dismissed_and_never_reads_healthy(): void
    {
        $business = $this->baseBusiness([
            'billing_status' => 'past_due', 'square_customer_id' => 'sq_1',
            'friend_reward_text' => '$10 off your first visit', 'referrer_reward_text' => '$10 off',
            'google_review_url' => 'https://www.google.com/maps/place/example',
        ]);
        $owner = $this->verifiedOwner($business);
        $this->posterEvent($business);
        $this->selfMembership($business, $owner);
        $this->succeededCard($business);

        $page = $this->actingAs($owner)->get(route('actions.index'));
        $page->assertOk();
        $page->assertSee('A payment failed. Please update your card to keep billing current.');
        // The one row on the page carries no Dismiss control.
        $page->assertDontSee('>Dismiss<', false);

        // A posted dismissal for it is refused outright.
        $this->actingAs($owner)->post(route('actions.dismiss', 0))->assertNotFound();
        $this->actingAs($owner)->post(route('actions.dismiss', 'past_due'))->assertNotFound();

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertSee('A payment failed. Please update your card to keep billing current.')
            ->assertDontSee('Account in good standing');

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('A payment failed. Please update your card to keep billing current.');
    }

    public function test_s7_removed_card_produces_the_no_card_row(): void
    {
        $business = $this->baseBusiness([
            'billing_status' => 'active', 'square_customer_id' => 'sq_1',
            'friend_reward_text' => '$10 off your first visit', 'referrer_reward_text' => '$10 off',
            'google_review_url' => 'https://www.google.com/maps/place/example',
        ]);
        $owner = $this->verifiedOwner($business);
        $this->posterEvent($business);
        $this->selfMembership($business, $owner);
        $method = $this->succeededCard($business);
        // The owner removes their only card (removeCard
        // semantics: the flow is never blocked, billing stays
        // active — charges accrue with nothing to charge).
        $method->forceFill(['state' => PaymentMethod::STATE_REMOVED, 'is_default' => false])->save();
        [, $plain] = World::qr($business);

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            // RE-DERIVED (activation redesign, census C-25): a
            // removed card re-enters the allowance state the
            // same day (pass Section 4e) — the row is the
            // before-visits form, and the retired
            // charges-keep-running line is gone with its
            // premise (the code now pauses when the free
            // visits end).
            ->assertSee('You have no card on file. You have 5 of your 5 free visits left, and your code stays on while they last.')
            ->assertDontSee('Add one so your monthly charges can run.')
            ->assertSee('Add a card')
            ->assertSee('href="'.route('settings.billing').'#card"', false)
            // One state, one row: the setup row does not also
            // render for the card step alone.
            ->assertDontSee('Your setup is not finished')
            ->assertDontSee('Account in good standing');

        $dashboard = $this->actingAs($owner)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertSee('data-action-center-badge', false);
        $dashboard->assertSee('>1</span>', false);
        // The required set is complete without the card, so
        // the checklist card no longer reopens for it; the
        // optional card step reads open on the checklist only
        // while setup is incomplete.
        $dashboard->assertDontSee('Setup checklist');

        // The flow is live — that is exactly why the missing
        // card needs its own row.
        $this->get(route('refer.landing', ['token' => $plain]))->assertOk();
    }

    public function test_s5_stale_held_reward_is_one_row_counted_once(): void
    {
        $business = $this->baseBusiness([
            'billing_status' => 'active', 'square_customer_id' => 'sq_1',
            'friend_reward_text' => '$10 off your first visit', 'referrer_reward_text' => '$10 off',
            // google_review_url stays null: every REQUIRED step
            // is done (ON-SET-2, so no setup row) AND the
            // review-link row armed by the redemption below.
        ]);
        $owner = $this->verifiedOwner($business);
        $this->posterEvent($business);
        $this->selfMembership($business, $owner);
        $this->succeededCard($business);

        $member = World::customer('+12125550101', 'Pat', 'One');
        [$membership] = World::membership($business, $member);
        $friend = World::customer('+12125550102', 'Fran', 'Two');
        [$redeemed] = World::referral($membership, $friend, ['state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()]);

        [$heldReward] = World::reward($membership, $redeemed);
        $heldReward->forceFill(['hold' => true, 'held_at' => now()->subDays(10)])->save();

        $friend2 = World::customer('+12125550103', 'Sam', 'Three');
        [$heldReferral] = World::referral($membership, $friend2);
        $heldReferral->forceFill(['hold' => true, 'held_at' => now()])->save();

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            // One row for the whole queue, the stale fact
            // folded into its line (AC-03 as amended).
            ->assertSee('2 items are waiting for your review. One has been waiting 10 days.')
            // The retired standalone stale row renders nowhere.
            ->assertDontSee('A reward has been waiting')
            // ON-SET-2: the open optional link alone never
            // produces the setup row.
            ->assertDontSee('Your setup is not finished');

        // Badge: review row + review-link row = 2 (ON-SET-2
        // removed the setup row from this state: its only open
        // step was the optional link).
        $dashboard = $this->actingAs($owner)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertSee('>2</span>', false);
    }

    public function test_s8c_dismissal_fingerprint_keys_on_composition_not_count(): void
    {
        $business = $this->baseBusiness();
        $owner = $this->verifiedOwner($business);
        $this->posterEvent($business);
        $this->selfMembership($business, $owner);
        // 3 of 4 required: email, poster, test done; open required = rewards (the card and the link are optional and key nothing).

        $this->actingAs($owner)->post(route('actions.dismiss', 'setup_checklist'))
            ->assertRedirect();

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertDontSee('Your setup is not finished')
            // Dismissed is quiet, never healthy (G3).
            ->assertSee('Nothing new needs you right now.')
            ->assertDontSee('Account in good standing');

        // The composition changes at the SAME count: rewards
        // completed, the self membership removed so the test
        // step reopens. 3 of 4 required still — but a state the owner
        // never saw, so the row resurfaces.
        $business->forceFill(['friend_reward_text' => '$10 off', 'referrer_reward_text' => '$10 off'])->save();
        \App\Models\Membership::query()->where('business_id', $business->id)
            ->update(['removed_at' => now()]);

        // (actingAs reuses this user instance across requests,
        // so refresh it: a real request loads the user — and
        // its business relation — fresh.)
        $this->actingAs($owner->fresh())->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Your setup is not finished: 3 of 4 required steps done.');
    }

    public function test_s8b_dashboard_and_action_center_share_one_setup_dismissal(): void
    {
        $business = $this->baseBusiness();
        $owner = $this->verifiedOwner($business);
        $this->posterEvent($business);
        $this->selfMembership($business, $owner);
        // 3 of 4 required, setup row + checklist card both visible.

        // Dismiss from the DASHBOARD card: the Action Center
        // row hides too — one dismissal domain (G6) — so the
        // row's "Finish setup" link can never land on a
        // checklist-less dashboard.
        $this->actingAs($owner)->post(route('dashboard.checklist.dismiss'))
            ->assertRedirect();

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Setup checklist');

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertDontSee('Your setup is not finished');

        // Settings offers the way back while it is dismissed.
        $this->actingAs($owner)->get(route('settings'))
            ->assertOk()
            ->assertSee('Show the setup checklist again');

        // A step REOPENING changes the composition: both
        // surfaces resurface together.
        // RE-DERIVED (activation redesign, 2026-10-30): the old
        // resurface trigger completed the rewards step to reach
        // 4 of 5; under the 4-step required set that completion
        // FINISHES setup (4 of 4), which closes both surfaces
        // for the opposite reason. The shared-domain property
        // is therefore pinned with a reopening instead: the
        // self membership is removed, the test step reopens,
        // 2 of 4 — a composition the owner never dismissed.
        \App\Models\Membership::query()->where('business_id', $business->id)
            ->update(['removed_at' => now()]);

        // (Fresh user instance from here: actingAs reuses one
        // instance across requests and its business relation
        // is cached with the pre-save attributes.)
        $owner = $owner->fresh();
        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Setup checklist')
            ->assertSee('2 of 4 required steps done');

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Your setup is not finished: 2 of 4 required steps done.');

        // The reverse direction is the same domain: dismiss
        // from the Action Center, the dashboard card hides.
        $this->actingAs($owner)->post(route('actions.dismiss', 'setup_checklist'))
            ->assertRedirect();

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Setup checklist');

        // And the Settings reset restores both.
        $this->actingAs($owner)->post(route('settings.checklist.reset'))
            ->assertRedirect();

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Setup checklist');
    }

    public function test_dismissed_review_hold_shows_the_quiet_line_not_good_standing(): void
    {
        $business = $this->baseBusiness([
            'billing_status' => 'active', 'square_customer_id' => 'sq_1',
            'friend_reward_text' => '$10 off your first visit', 'referrer_reward_text' => '$10 off',
            'google_review_url' => 'https://www.google.com/maps/place/example',
        ]);
        $owner = $this->verifiedOwner($business);
        $this->posterEvent($business);
        $this->selfMembership($business, $owner);
        $this->succeededCard($business);

        $referrer = World::customer('+12125550121', 'Pat', 'One');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550122', 'Fran', 'Two');
        [$referral] = World::referral($membership, $friend);
        $referral->forceFill(['hold' => true, 'held_at' => now()])->save();

        $this->actingAs($owner)->post(route('actions.dismiss', 'review_holds'))
            ->assertRedirect();

        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertDontSee('waiting for your review')
            ->assertSee('Nothing new needs you right now.')
            ->assertDontSee('Account in good standing');
    }
}
