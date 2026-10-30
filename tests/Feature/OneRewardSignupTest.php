<?php

namespace Tests\Feature;

use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Referral;
use App\Models\Reward;
use App\Support\StarterPacks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ONE-REWARD-AT-SIGNUP (owner ruling 2026-10-28 23:24/23:25;
 * design pass night-loop/one-reward-design-pass.md, register
 * OR-1, copy family ORS / Amendment 49): signup asks for ONE
 * reward; friend and referrer get the same reward by default;
 * splitting lives only in Settings behind the toggle named
 * "Give the referrer something different". Configuration is
 * the stored flag (Business::rewardsAreSplit) + canonical
 * storage + resolver-only reads. These pins assert BOTH
 * directions on rendered output (unified renders unified
 * copy everywhere; split renders the shipped pair copy), the
 * §2.6 mid-flight rules, the Settings transitions, and the
 * §3.4 jargon grep over rendered signup output.
 */
class OneRewardSignupTest extends TestCase
{
    use RefreshDatabase;

    /** A business that has not set any reward yet. */
    private function unsetBusiness(array $overrides = []): Business
    {
        return World::business(array_merge([
            'billing_status' => 'none',
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
            'reward_split' => false,
        ], $overrides));
    }

    private function unifiedBusiness(array $overrides = []): Business
    {
        return World::business(array_merge([
            'friend_reward_text' => 'A free coffee',
            'referrer_reward_text' => null,
            'reward_split' => false,
        ], $overrides));
    }

    private function splitBusiness(array $overrides = []): Business
    {
        return World::business(array_merge([
            'friend_reward_text' => '$10 off your first cut',
            'referrer_reward_text' => '$10 off your next cut',
            'reward_split' => true,
        ], $overrides));
    }

    // ---------- §2: the model ----------

    public function test_resolver_reads_the_flag_never_column_nullness_or_text_equality(): void
    {
        $unified = $this->unifiedBusiness();
        $this->assertFalse($unified->rewardsAreSplit());
        $this->assertSame('A free coffee', $unified->rewardText());
        $this->assertSame('A free coffee', $unified->effectiveReferrerRewardText());

        $split = $this->splitBusiness();
        $this->assertTrue($split->rewardsAreSplit());
        $this->assertSame('$10 off your next cut', $split->effectiveReferrerRewardText());

        // SPLIT-EQUAL: flag on, texts equal — still split.
        $splitEqual = $this->splitBusiness(['referrer_reward_text' => '$10 off your first cut']);
        $this->assertTrue($splitEqual->rewardsAreSplit());

        // An orphaned referrer column under a unified flag does
        // NOT make the business split (canonical-form rule).
        $orphan = $this->unifiedBusiness(['referrer_reward_text' => 'Something else']);
        $this->assertFalse($orphan->rewardsAreSplit());
        $this->assertSame('A free coffee', $orphan->effectiveReferrerRewardText());

        $unset = $this->unsetBusiness();
        $this->assertNull($unset->rewardText());
        $this->assertNull($unset->effectiveReferrerRewardText());
    }

    public function test_starter_packs_are_single_rewards_with_the_pass_picks(): void
    {
        $generic = StarterPacks::forType(null);
        $this->assertSame('20% off', $generic[0]['reward']);
        foreach (['barber', 'salon', 'cafe', 'boutique', 'generic'] as $type) {
            foreach (StarterPacks::forType($type) as $pack) {
                $this->assertArrayHasKey('reward', $pack);
                $this->assertArrayNotHasKey('friend', $pack);
                $this->assertArrayNotHasKey('referrer', $pack);
            }
        }
        $this->assertSame('A free regular coffee', StarterPacks::forType('cafe')[0]['reward']);
        $this->assertSame('A free drink', StarterPacks::forType('cafe')[2]['reward']);
        $this->assertSame('$10 off', StarterPacks::forType('barber')[1]['reward']);
    }

    // ---------- §3: the signup step ----------

    public function test_signup_reward_step_prefills_one_reward_and_names_no_pair(): void
    {
        $business = $this->unsetBusiness(['business_type' => 'cafe']);
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('onboarding.step', 'rewards'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('What do you want to give a new customer who comes in from a referral?', $html);
        // Owner-dictated label (2026-10-29 restatement): the one
        // field is labelled "Referral Reward", one line.
        $this->assertStringContainsString('<label for="friend_reward_text">Referral Reward</label>', $html);
        // The prefilled starter answer sits IN the field.
        $this->assertStringContainsString('value="A free regular coffee"', $html);
        $this->assertStringContainsString('The customer who sent them gets the same reward.', $html);
        $this->assertStringContainsString('Typical cost:', $html);
        $this->assertStringContainsString('Use this reward', $html);
        $this->assertStringContainsString('See other ideas', $html);
        // Exactly one reward field is presented.
        $this->assertSame(1, substr_count($html, 'name="friend_reward_text"'));
        $this->assertStringNotContainsString('name="referrer_reward_text"', $html);
    }

    public function test_signup_jargon_grep_is_zero_in_fresh_and_done_states(): void
    {
        $banned = ['pair', 'pairs', 'paired', 'both sides', 'two-sided', 'two rewards',
            'For the friend', 'For the referrer', 'What do both sides get', 'welcome reward'];

        $business = $this->unsetBusiness();
        $owner = World::owner($business);

        $fresh = $this->actingAs($owner)->get(route('onboarding.step', 'rewards'))->assertOk()->getContent();
        foreach ($banned as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $fresh, "Banned signup term [{$term}] in the fresh step.");
        }

        // C-7b (two-key review): the finish screen's
        // rewards-missing branch (ORS-07) is in the grep scope,
        // so it must be fetched while the owner is still UNSET,
        // the only state where that branch renders. Asserting
        // the ORS-07 line is present proves the branch is
        // actually inside the HTML being grepped (the previous
        // version fetched finish AFTER the reward was posted,
        // so this leg passed vacuously).
        $finish = $this->actingAs($owner)->get(route('onboarding.finish'))->assertOk()->getContent();
        // FLOW-STATE TRUTH train: an UNSET owner is missing
        // BOTH activation gates (billing_status none + no
        // reward), so the branch that renders is the both-gates
        // branch — the rewards-only ORS-07 line was defect
        // A-D1's false claim in exactly this state. The grep
        // scope intent is unchanged: the branch that renders
        // for an unset owner is inside the grepped HTML.
        $this->assertStringContainsString('Two things are still missing: your rewards and a card on file. Set your reward and add a card, and your code turns on; visits are free until you pass 5.', $finish, 'C-7b: the rewards-missing (both-gates) branch must render for an UNSET owner.');
        foreach (array_merge($banned, ['friend reward', 'referrer reward']) as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $finish, "Banned signup term [{$term}] in the finish rewards-missing branch.");
        }

        $this->actingAs($owner)->post(route('owner.reward-texts'), [
            'friend_reward_text' => 'A free coffee',
        ])->assertRedirect();

        $owner->unsetRelation('business');
        $done = $this->actingAs($owner)->get(route('onboarding.step', 'rewards'))->assertOk()->getContent();
        $this->assertStringContainsString('Reward set.', $done);
        foreach ($banned as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $done, "Banned signup term [{$term}] in the done step.");
        }
    }

    public function test_accepting_the_prefilled_reward_advances_in_one_tap_and_stores_unified(): void
    {
        $business = $this->unsetBusiness();
        $owner = World::owner($business);

        // The accept path: the field still holds a pack text, so
        // the save advances straight to the Card step.
        $this->actingAs($owner)->post(route('owner.reward-texts'), [
            'friend_reward_text' => '20% off',
            'advance' => '1',
        ])->assertRedirect(route('onboarding.step', 'card'));

        $fresh = $business->fresh();
        $this->assertSame('20% off', $fresh->friend_reward_text);
        $this->assertNull($fresh->referrer_reward_text);
        $this->assertFalse($fresh->rewardsAreSplit());
    }

    public function test_edited_reward_goes_through_the_done_panel_readback(): void
    {
        $business = $this->unsetBusiness();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('owner.reward-texts'), [
            'friend_reward_text' => 'A free pastry with any drink',
            'advance' => '1',
        ])->assertRedirect(route('onboarding.step', 'rewards'));

        $owner->unsetRelation('business');
        $html = $this->actingAs($owner)->get(route('onboarding.step', 'rewards'))->assertOk()->getContent();
        $this->assertStringContainsString('Reward set.', $html);
        $this->assertStringContainsString('A free pastry with any drink', $html);
        $this->assertStringContainsString('You can change it any time in Settings.', $html);
    }

    public function test_a_handcrafted_signup_post_with_a_referrer_value_still_stores_unified(): void
    {
        $business = $this->unsetBusiness();
        $owner = World::owner($business);

        $this->actingAs($owner)->post(route('owner.reward-texts'), [
            'friend_reward_text' => 'A free coffee',
            'referrer_reward_text' => 'A free yacht',
        ])->assertRedirect();

        $fresh = $business->fresh();
        $this->assertNull($fresh->referrer_reward_text);
        $this->assertFalse($fresh->rewardsAreSplit());
        $this->assertSame('A free coffee', $fresh->effectiveReferrerRewardText());
    }

    // ---------- §1.3 B/D: customer + print rendering, both directions ----------

    public function test_unified_business_renders_unified_copy_everywhere(): void
    {
        $business = $this->unifiedBusiness();
        $referrer = World::customer('+12125550101', 'Sam', 'Sender');
        [$membership, $shareToken] = World::membership($business, $referrer);

        // Friend page (ORS-03), and NOT the split RK-06 line.
        $friend = $this->get(route('refer.friend', ['token' => $shareToken]))->assertOk()->getContent();
        $this->assertStringContainsString('You both get A free coffee when you visit.', $friend);
        $this->assertStringNotContainsString('Sam gets', $friend);

        // Receipt prime (ORS-04): the unified line, composed
        // from the normalized reward — never the friend
        // headline's first-visit suffix.
        $prime = $this->primeOfferLine($business);
        $this->assertStringContainsString('Share your link with a friend. You both get A free coffee when they visit.', $prime);
        $this->assertStringNotContainsString('They get', $prime);

        // Print composer (ORS-05) on every variant surface.
        $this->assertSame('Give A free coffee. Get A free coffee when your friend visits.', $business->giveGetLine());

        // Poster: the YOUR REWARD card is PRESENT with the unified value.
        // Amendment 58: read as rendered text — the headline's
        // F5 amount span interrupts the reward phrase in raw
        // markup ("<span>A</span> free coffee"), so the raw-HTML
        // substring form of this pin went false while the
        // printed text stayed true.
        $owner = World::owner($business);
        World::qr($business);
        $poster = $this->actingAs($owner)->get(route('owner.qr.poster', 'counter'))->assertOk()->getContent();
        $this->assertStringContainsString('YOUR REWARD', $poster);
        $posterText = (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($poster)));
        $this->assertStringContainsString('A free coffee', $posterText);
        $this->assertStringContainsString($business->posterHeadline(), $posterText);

        // QR page: RC-2 panel present (reward set), poster-says
        // carries one text + the ORS-06 sameness line.
        $qr = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $this->assertStringContainsString('start-here-pair', $qr);
        $this->assertStringContainsString('The same reward goes to the customer who sent them.', $qr);
    }

    public function test_split_business_renders_the_shipped_pair_copy_everywhere(): void
    {
        $business = $this->splitBusiness();
        $referrer = World::customer('+12125550102', 'Sam', 'Sender');
        [$membership, $shareToken] = World::membership($business, $referrer);

        $friend = $this->get(route('refer.friend', ['token' => $shareToken]))->assertOk()->getContent();
        $this->assertStringContainsString('Sam gets $10 off your next cut when you visit.', $friend);
        $this->assertStringNotContainsString('You both get', $friend);

        // Receipt prime: the shipped composed line, byte-level.
        $prime = $this->primeOfferLine($business);
        $this->assertStringContainsString('Share your link with a friend. They get $10 off your first cut, and you get $10 off your next cut when they visit.', $prime);

        $this->assertSame('Give $10 off. Get $10 off your next cut when your friend visits.', $business->giveGetLine());

        $owner = World::owner($business);
        World::qr($business);
        $qr = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $this->assertStringContainsString('start-here-pair', $qr);
        $this->assertStringContainsString('$10 off your next cut', $qr);
        $this->assertStringNotContainsString('The same reward goes to the customer who sent them.', $qr);
    }

    public function test_split_equal_renders_exactly_as_split(): void
    {
        $business = $this->splitBusiness(['referrer_reward_text' => '$10 off your first cut']);
        $referrer = World::customer('+12125550103', 'Sam', 'Sender');
        [, $shareToken] = World::membership($business, $referrer);

        $friend = $this->get(route('refer.friend', ['token' => $shareToken]))->assertOk()->getContent();
        $this->assertStringContainsString('Sam gets $10 off your first cut when you visit.', $friend);
        $this->assertStringNotContainsString('You both get', $friend);
        $this->assertSame('Give $10 off. Get $10 off your first cut when your friend visits.', $business->giveGetLine());
    }

    public function test_unset_business_has_no_pair_panel_and_no_reward_card(): void
    {
        $business = $this->unsetBusiness(['billing_status' => 'active']);
        $owner = World::owner($business);
        World::qr($business);

        $qr = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $this->assertStringNotContainsString('start-here-pair', $qr);
        $this->assertSame('Scan, share, and you both get rewarded when your friend visits.', $business->giveGetLine());
    }

    /**
     * The RS-N2 prime's offer line for a business: build a
     * redeemed visit (Reg referred Fri; Fri holds a membership)
     * and read the redeem receipt. Pattern from ReceiptPrimeTest.
     */
    private function primeOfferLine(Business $business): string
    {
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$referrerMembership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        World::membership($business, $friend);
        [, $redeemToken] = World::referral($referrerMembership, $friend, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);

        return $this->get(route('refer.redeem.page', ['token' => $redeemToken]))->assertOk()->getContent();
    }

    // ---------- §4: Settings ----------

    private function settingsSave(Business $business, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $owner = World::owner($business);

        return $this->actingAs($owner)->post(route('settings.rewards.update'), array_merge([
            'friend_reward_text' => 'A free coffee',
            'redemption_window_days' => 30,
            'reward_vesting_days' => 14,
        ], $overrides));
    }

    public function test_settings_page_names_the_reward_and_the_split_toggle(): void
    {
        $business = $this->unifiedBusiness();
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('settings.rewards'))->assertOk()->getContent();
        $this->assertStringContainsString('Your reward, and the timing.', $html);
        $this->assertStringContainsString('The reward', $html);
        $this->assertStringContainsString('Give the referrer something different', $html);
        $this->assertStringContainsString('What a new customer gets:', $html);
        // Unified preview: ONE reward line — the referrer line
        // exists only inside the toggle-bound (x-show="split")
        // container, so it cannot render while unified.
        $this->assertStringContainsString('x-show="split"', $html);
    }

    public function test_splitting_on_prefills_and_stores_split_and_blank_is_refused(): void
    {
        $business = $this->unifiedBusiness();

        // Blank referrer with the toggle on: refused, nothing stored.
        $this->settingsSave($business, ['reward_split' => '1', 'referrer_reward_text' => ''])
            ->assertSessionHasErrors('referrer_reward_text');
        $this->assertFalse($business->fresh()->rewardsAreSplit());

        $this->settingsSave($business, ['reward_split' => '1', 'referrer_reward_text' => 'A free pastry'])
            ->assertRedirect();
        $fresh = $business->fresh();
        $this->assertTrue($fresh->rewardsAreSplit());
        $this->assertSame('A free pastry', $fresh->referrer_reward_text);
        $this->assertSame('A free coffee', $fresh->friend_reward_text);
    }

    public function test_unifying_stores_canonical_unified_and_undo_restores_the_split_whole(): void
    {
        $business = $this->splitBusiness();

        $response = $this->settingsSave($business, ['friend_reward_text' => '$10 off your first cut']);
        $response->assertRedirect();
        $fresh = $business->fresh();
        $this->assertFalse($fresh->rewardsAreSplit());
        $this->assertNull($fresh->referrer_reward_text);

        $undoToken = session('toast')['undo']['token'];
        $owner = World::owner($business);
        $this->actingAs($owner)->post(route('actions.undo'), ['undo_token' => $undoToken])->assertRedirect();
        $restored = $business->fresh();
        $this->assertTrue($restored->rewardsAreSplit());
        $this->assertSame('$10 off your next cut', $restored->referrer_reward_text);
    }

    // ---------- §2.6: mid-flight ----------

    public function test_minted_rewards_are_immune_to_split_and_unify_flips(): void
    {
        $business = $this->unifiedBusiness();
        $referrer = World::customer('+12125550104', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550105', 'Fri', 'End');
        [$referral] = World::referral($membership, $friend);
        [$reward, $claimToken] = World::reward($membership, $referral, [
            'reward_text_snapshot' => 'A free coffee',
        ]);

        // Split during vesting: the minted reward still pays its snapshot.
        $business->setRewardConfiguration('A free coffee', true, 'A free yacht');
        $page = $this->get(route('refer.claim.page', ['token' => $claimToken]))->assertOk()->getContent();
        $this->assertStringContainsString('A free coffee', $page);
        $this->assertStringNotContainsString('A free yacht', $page);

        // Unify during vesting: same.
        $business->setRewardConfiguration('A free coffee', false, null);
        $page = $this->get(route('refer.claim.page', ['token' => $claimToken]))->assertOk()->getContent();
        $this->assertStringContainsString('A free coffee', $page);
        $this->assertSame('A free coffee', $reward->fresh()->reward_text_snapshot);
    }

    public function test_pending_referral_pays_the_configuration_in_force_at_redemption(): void
    {
        // §2.6 rule 2, both directions, driven through the real
        // redemption service (the MeteringPinsTest pattern):
        // a referral PENDING at the flip pays, at redemption,
        // the configuration in force AT REDEMPTION.
        $drive = function (Business $business, string $phoneSuffix): string {
            $referrer = World::customer('+121255502'.$phoneSuffix, 'Reg', 'Ular');
            [$membership] = World::membership($business, $referrer);
            $friend = World::customer('+121255503'.$phoneSuffix, 'Fri', 'End');
            [$referral] = World::referral($membership, $friend);
            $result = app(\App\Services\Referral\RedemptionService::class)
                ->redeemReferral($referral->fresh(), null, '127.0.0.1');
            $this->assertSame('redeemed', $result['outcome']);

            return (string) $result['reward']->reward_text_snapshot;
        };

        // Pending under UNIFIED → split → redeem: SPLIT text mints.
        $business = $this->unifiedBusiness();
        $referrer = World::customer('+12125550111', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550112', 'Fri', 'End');
        [$pending] = World::referral($membership, $friend);
        $business->setRewardConfiguration('A free coffee', true, 'A free pastry');
        $result = app(\App\Services\Referral\RedemptionService::class)
            ->redeemReferral($pending->fresh(), null, '127.0.0.1');
        $this->assertSame('redeemed', $result['outcome']);
        $this->assertSame('A free pastry', $result['reward']->reward_text_snapshot);

        // Pending under SPLIT → unify → redeem: the unified text mints.
        $business2 = $this->splitBusiness();
        $business2->setRewardConfiguration('$10 off your first cut', false, null);
        $this->assertSame('$10 off your first cut', $drive($business2, '21'));

        // §2.6 rule 4: a configuration flip touches NO billing
        // state — the ledger is byte-identical across the flip.
        $before = BillingEvent::query()->orderBy('id')
            ->get(['business_id', 'amount_cents', 'coverage', 'kind'])
            ->map(fn ($event) => $event->toArray())->all();
        $business->setRewardConfiguration('A free coffee', false, null);
        $business->setRewardConfiguration('A free coffee', true, 'A free yacht');
        $after = BillingEvent::query()->orderBy('id')
            ->get(['business_id', 'amount_cents', 'coverage', 'kind'])
            ->map(fn ($event) => $event->toArray())->all();
        $this->assertSame($before, $after);
        $this->assertNotSame([], $before);
    }

    // ---------- §1.3 E6/A4: finish + settings index ----------

    public function test_finish_rewards_missing_branch_names_one_reward(): void
    {
        $business = $this->unsetBusiness();
        $owner = World::owner($business);

        $html = $this->actingAs($owner)->get(route('onboarding.finish'))->assertOk()->getContent();
        // FLOW-STATE TRUTH train: the unset owner is missing
        // both gates, so the both-gates branch renders; it
        // still names ONE reward ("Set your reward"), never a
        // pair — the one-reward intent of this pin is intact.
        $this->assertStringContainsString('Set your reward and add a card, and your code turns on; visits are free until you pass 5.', $html);
    }

    public function test_settings_index_line_marks_a_split_business(): void
    {
        // C-1 (two-key review): the marker must be true in
        // SPLIT and in SPLIT-EQUAL alike. "A separate one" is
        // true in both (a separate setting exists either way);
        // "a different one" was false when the stored texts
        // were equal, so that wording must be gone everywhere.
        $split = $this->splitBusiness();
        $owner = World::owner($split);
        $html = $this->actingAs($owner)->get(route('settings'))->assertOk()->getContent();
        $this->assertStringContainsString('a separate one for referrers', $html);
        $this->assertStringNotContainsString('a different one for referrers', $html);

        $splitEqual = $this->splitBusiness(['referrer_reward_text' => '$10 off your first cut']);
        $ownerEqual = World::owner($splitEqual);
        $htmlEqual = $this->actingAs($ownerEqual)->get(route('settings'))->assertOk()->getContent();
        $this->assertStringContainsString('a separate one for referrers', $htmlEqual);
        $this->assertStringNotContainsString('a different one for referrers', $htmlEqual);

        $unified = $this->unifiedBusiness();
        $owner2 = World::owner($unified);
        $html2 = $this->actingAs($owner2)->get(route('settings'))->assertOk()->getContent();
        $this->assertStringNotContainsString('a separate one for referrers', $html2);
        $this->assertStringNotContainsString('a different one for referrers', $html2);
    }

    public function test_qr_poster_says_split_with_blank_referrer_renders_the_resolver_text(): void
    {
        // C-2 (two-key review): SPLIT with a blank referrer
        // column is non-canonical (reachable via a pre-OR-1
        // Undo restore or a direct write). The resolver falls
        // back to the friend text in that state, and the
        // poster promises that text to the referrer, so the
        // "What your poster says" panel must show it as the
        // referrer line too, not silently show nothing. The
        // ORS-06 sameness line stays UNIFIED-only.
        $business = $this->splitBusiness(['referrer_reward_text' => null]);
        $owner = World::owner($business);
        World::qr($business);

        $qr = $this->actingAs($owner)->get(route('owner.qr'))->assertOk()->getContent();
        $this->assertStringContainsString('data-poster-referrer-text>$10 off your first cut</p>', $qr);
        $this->assertStringNotContainsString('The same reward goes to the customer who sent them.', $qr);
    }

    public function test_settings_split_prefill_keys_on_the_stored_flag_not_an_orphaned_column(): void
    {
        // C-3 (two-key review): a UNIFIED business can carry an
        // orphaned divergent referrer column (every pre-OR-1
        // pair business). Its Settings form must start the
        // referrer field from the CURRENT reward, as its
        // helper says ("Starts as the same reward."), never
        // from the orphan. A SPLIT business starts from its
        // stored referrer text.
        $orphan = $this->unifiedBusiness(['referrer_reward_text' => 'An orphaned old reward']);
        $owner = World::owner($orphan);
        $html = $this->actingAs($owner)->get(route('settings.rewards'))->assertOk()->getContent();
        $this->assertStringContainsString("referrer: 'A free coffee'", $html);
        $this->assertStringNotContainsString("referrer: 'An orphaned old reward'", $html);

        $split = $this->splitBusiness();
        $owner2 = World::owner($split);
        $html2 = $this->actingAs($owner2)->get(route('settings.rewards'))->assertOk()->getContent();
        $this->assertStringContainsString("referrer: '\$10 off your next cut'", $html2);
    }

    public function test_unify_confirm_second_quote_binds_the_live_reward_field(): void
    {
        // C-4 (two-key review): the confirm's second quote
        // names what referrers will get AFTER the save, and
        // the save stores the form's LIVE reward field, so
        // the quote must bind the live Alpine model (friend),
        // not the stored reward. The first quote stays bound
        // to the stored referrer value.
        $business = $this->splitBusiness();
        $owner = World::owner($business);
        $html = $this->actingAs($owner)->get(route('settings.rewards'))->assertOk()->getContent();

        $pos = strpos($html, 'id="unify-confirm"');
        $this->assertNotFalse($pos);
        $confirm = substr($html, $pos, 900);
        $this->assertStringContainsString('x-text="storedReferrer"', $confirm);
        $this->assertStringContainsString('referrers get \'<span x-text="friend"></span>\' instead', $confirm);
        $this->assertStringNotContainsString('x-text="storedReward"', $html);
    }

    public function test_flow_activation_truth_table_is_unchanged(): void
    {
        $this->assertFalse($this->unsetBusiness(['billing_status' => 'active'])->flowIsActive());
        $this->assertTrue($this->unifiedBusiness()->flowIsActive());
        $this->assertTrue($this->splitBusiness()->flowIsActive());
    }
}
