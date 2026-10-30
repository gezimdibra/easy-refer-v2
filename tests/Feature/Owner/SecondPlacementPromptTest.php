<?php

namespace Tests\Feature\Owner;

use App\Contracts\MarketingSmsGateway;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\SecondPlacementPrompt;
use App\Models\SecondPlacementPromptImpression;
use App\Models\User;
use App\Services\Owner\ActionCenter;
use App\Services\Owner\SecondPlacement;
use App\Services\Referral\RedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ID-06 (Amendment 49; design pass idea-06-design-pass.md):
 * the second-code prompt at the first SETTLED visit. One
 * Action Center row (key second_placement, urgency 82), once
 * per business ever, sending nothing. Settled = ID-02's
 * three-axis law (redeemed + billing coverage NOT IN
 * held/fraud_denied/refunded + referrer reward not held and
 * not denied; vesting never blocks). A held first visit arms
 * silently and fires at release; "placed" = a poster print
 * (the shipped qr.poster_downloaded audit record's variant).
 *
 * The pins below are the pass's Section 6.4 suppression proof
 * and Section 10 test list, both directions (presence AND
 * absence). The offer ORDER (Section 4.1) is pinned against
 * SecondPlacement::offerFor directly, including the shapes
 * the firing gate (placed count < 2, Sections 1.1/5.1/5.2 L1)
 * keeps off the page: exactly-2-printed never renders
 * (Section 6.4 item 2, "any pair").
 */
class SecondPlacementPromptTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 4200;

    private FakeMarketingSmsGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeMarketingSmsGateway;
        $this->app->instance(MarketingSmsGateway::class, $this->gateway);
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when, 'America/New_York'));
    }

    private function member(Business $business): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        return $membership->fresh();
    }

    /**
     * One visit by a fresh friend, redeemed $daysAgo before
     * the current test now unless overridden, with its billing
     * event and referrer reward.
     *
     * @param  array{coverage?: ?string, reward?: array<string, mixed>, redeemed_at?: Carbon}  $opts
     */
    private function visit(Membership $referrer, float $daysAgo = 1, array $opts = []): Referral
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        $friend = World::customer($phone, 'Fri', 'End');
        $redeemedAt = $opts['redeemed_at'] ?? now()->subDays($daysAgo);
        [$referral] = World::referral($referrer, $friend, [
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => $redeemedAt,
        ]);
        $referral->forceFill(['created_at' => $redeemedAt->copy()->subDays(2)])->save();

        if (($opts['coverage'] ?? 'billable') !== null) {
            $event = BillingEvent::create([
                'business_id' => $referrer->business_id,
                'referral_id' => $referral->id,
                'amount_cents' => 200,
                'coverage' => $opts['coverage'] ?? 'billable',
            ]);
            $event->forceFill(['created_at' => $redeemedAt])->save();
        }

        World::reward($referrer, $referral, $opts['reward'] ?? []);

        return $referral->fresh();
    }

    /** The referrer's join scan (the P1 evidence), at a past instant. */
    private function scan(Business $business, Membership $membership, ?string $placement, Carbon $at): void
    {
        $event = ReferralEvent::record(
            'scan',
            'customer',
            $business,
            null,
            null,
            $membership,
            $placement === null ? [] : ['placement' => $placement],
        );
        $event->forceFill(['created_at' => $at])->save();
    }

    /**
     * An audit row at a past instant. AuditLog rows are
     * immutable through Eloquent by design, so fixtures that
     * need a backdated row insert it directly (the
     * VolumeFixture pattern for history-shaped rows).
     */
    private function auditAt(Business $business, User $owner, string $action, ?array $metadata, Carbon $at): void
    {
        \Illuminate\Support\Facades\DB::table('audit_logs')->insert([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'action' => $action,
            'metadata' => $metadata === null ? null : json_encode($metadata),
            'created_at' => $at->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'),
        ]);
    }

    /** A poster print (the placed evidence), variant as the poster route stamps it. */
    private function printAt(Business $business, User $owner, string $variant, Carbon $at): void
    {
        $this->auditAt($business, $owner, 'qr.poster_downloaded', ['variant' => $variant], $at);
    }

    /** @return array<string, mixed>|null the second_placement item, badge/dashboard read (no stamps) */
    private function item(Business $business, User $owner): ?array
    {
        foreach (app(ActionCenter::class)->items($business, $owner) as $item) {
            if ($item['key'] === 'second_placement') {
                return $item;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null the item via the Action Center page read (stamps land here) */
    private function pageItem(Business $business, User $owner): ?array
    {
        foreach (app(ActionCenter::class)->pageState($business, $owner)['items'] as $item) {
            if ($item['key'] === 'second_placement') {
                return $item;
            }
        }

        return null;
    }

    private function promptState(Business $business): ?SecondPlacementPrompt
    {
        return SecondPlacementPrompt::query()->where('business_id', $business->id)->first();
    }

    // ---------- Detection: silence before proof (L0) ----------

    public function test_no_row_without_a_settled_visit(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);

        // Pending only.
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        World::referral($referrer, World::customer($phone));
        $this->assertNull($this->item($business, $owner));

        // Redeemed but refunded: never settled.
        $refunded = $this->visit($referrer, 1, ['coverage' => 'refunded']);
        $this->assertNull($this->item($business, $owner));
        $this->assertNotNull($refunded);
    }

    public function test_the_settled_axes_each_unsettle_on_their_own(): void
    {
        // Coverage held alone.
        $b1 = World::business();
        $this->visit($this->member($b1), 1, ['coverage' => 'held']);
        $this->assertNull($this->item($b1, World::owner($b1)), 'coverage held must not settle');

        // Reward held alone (coverage settled).
        $b2 = World::business();
        $this->visit($this->member($b2), 1, ['reward' => ['hold' => true, 'state' => 'pending_vesting']]);
        $this->assertNull($this->item($b2, World::owner($b2)), 'a held referrer reward must not settle');

        // Reward denied alone.
        $b3 = World::business();
        $this->visit($this->member($b3), 1, ['reward' => ['state' => 'denied']]);
        $this->assertNull($this->item($b3, World::owner($b3)), 'a denied referrer reward must not settle');

        // Coverage fraud_denied alone.
        $b4 = World::business();
        $this->visit($this->member($b4), 1, ['coverage' => 'fraud_denied']);
        $this->assertNull($this->item($b4, World::owner($b4)), 'fraud_denied coverage must not settle');

        // Free-credit and over-cap coverage DO settle (the
        // axis is NOT IN the excluded set, not "billable only").
        $b5 = World::business();
        $this->visit($this->member($b5), 1, ['coverage' => 'free_credit']);
        $this->assertNotNull($this->item($b5, World::owner($b5)), 'free_credit settles');

        $b6 = World::business();
        $this->visit($this->member($b6), 1, ['coverage' => 'over_cap']);
        $this->assertNotNull($this->item($b6, World::owner($b6)), 'over_cap settles');
    }

    public function test_vesting_never_blocks_settlement(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->visit($this->member($business), 1, [
            'reward' => ['state' => 'pending_vesting', 'available_at' => now()->addDays(13)],
        ]);

        $this->assertNotNull($this->item($business, $owner));
    }

    // ---------- The row (L3): copy, offer, action ----------

    public function test_row_renders_at_first_settled_visit_counter_proven_receipt_offered(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $this->scan($business, $referrer, 'counter', now()->subDays(5));
        $this->printAt($business, $owner, 'counter', now()->subDays(4));
        $this->visit($referrer, 1);

        $item = $this->item($business, $owner);
        $this->assertNotNull($item);
        $this->assertSame(82, $item['urgency']);
        $this->assertSame(
            'Your first visit that counts just came in, through your Counter poster. '
            .'Put a code in one more spot so more customers can join. Try the Receipt card: Handed out with the receipt.',
            $item['line'],
        );
        $this->assertSame('Print the receipt card', $item['action_label']);
        $this->assertSame(
            route('owner.qr.poster', ['variant' => 'receipt', 'print' => 1]),
            $item['url'],
        );
        $this->assertSame('Not now', $item['dismiss_label'] ?? null);
        $this->assertTrue($item['dismissible']);
    }

    public function test_row_renders_on_the_action_center_page_and_nowhere_else(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $this->scan($business, $referrer, 'counter', now()->subDays(5));
        $referral = $this->visit($referrer, 1);

        $page = $this->actingAs($owner)->get(route('actions.index'));
        $page->assertOk();
        $page->assertSee('Your first visit that counts just came in, through your Counter poster.');
        $page->assertSee('Put a code in one more spot so more customers can join.');
        $page->assertSee('Not now');

        // Section 6.4 item 7: no ID-06 string on the QR page,
        // the dashboard, the referral detail, or any poster.
        foreach ([
            route('owner.qr'),
            route('dashboard'),
            route('referrals.show', $referral),
            route('owner.qr.poster', ['variant' => 'counter']),
        ] as $url) {
            $response = $this->actingAs($owner)->get($url);
            $response->assertOk();
            $response->assertDontSee('Your first visit that counts just came in');
            $response->assertDontSee('Put a code in one more spot');
        }

        // The feature sends NOTHING, ever (Section 3.3).
        Mail::fake();
        $this->pageItem($business, $owner);
        $this->actingAs($owner)->get(route('actions.index'))->assertOk();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame([], $this->gateway->sent);
    }

    public function test_p1_unknown_link_join_uses_the_short_proof_line(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        // No scan event for this referrer: a link join.
        $this->visit($this->member($business), 1);

        $item = $this->item($business, $owner);
        $this->assertNotNull($item);
        $this->assertStringStartsWith('Your first visit that counts just came in. ', $item['line']);
        $this->assertStringNotContainsString('through your', $item['line']);
        // Fallback order: counter is the coverage floor.
        $this->assertSame('Print the counter poster', $item['action_label']);
    }

    public function test_renamed_placement_renders_its_current_name(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $this->scan($business, $referrer, 'counter', now()->subDays(5));
        $this->visit($referrer, 1);

        \App\Models\QrCode::ensurePlacements($business);
        $code = \App\Models\QrCode::activeForPlacement($business, 'counter');
        $code->forceFill(['label' => 'Front door'])->save();

        $item = $this->item($business, $owner);
        $this->assertNotNull($item);
        $this->assertStringContainsString('through your Front door.', $item['line']);
    }

    // ---------- Offer order (Section 4.1, pinned on the function) ----------

    public function test_offer_order_matches_the_design_table(): void
    {
        $offer = fn (array $placed, ?string $p1) => app(SecondPlacement::class)->offerFor($placed, $p1);

        $this->assertSame('receipt', $offer(['counter'], 'counter'), 'counter proven -> receipt');
        $this->assertSame('counter', $offer(['receipt'], 'receipt'), 'receipt proven -> counter');
        $this->assertSame('counter', $offer(['window'], 'window'), 'window proven -> counter');
        $this->assertSame('counter', $offer(['table'], 'table'), 'table proven -> counter');
        $this->assertSame('counter', $offer(['window'], null), 'link join, window printed -> counter');
        $this->assertSame('counter', $offer([], null), 'nothing placed, no proof -> counter');
        $this->assertSame('receipt', $offer(['counter', 'window'], 'counter'), 'order function: counter+window -> receipt (firing gate pinned separately)');
        $this->assertSame('window', $offer(['receipt', 'counter'], 'receipt'), 'counter placed -> window next');
        $this->assertSame('table', $offer(['window', 'counter', 'receipt'], 'window'), 'sole remaining gap -> table');
        $this->assertNull($offer(['counter', 'window', 'table', 'receipt'], 'counter'), 'no gap -> no offer');
    }

    public function test_offer_variants_render_their_labels_spots_and_urls(): void
    {
        // Window proven, nothing else placed: counter offered.
        $b1 = World::business();
        $o1 = World::owner($b1);
        $r1 = $this->member($b1);
        $this->scan($b1, $r1, 'window', now()->subDays(5));
        $this->printAt($b1, $o1, 'window', now()->subDays(4));
        $this->visit($r1, 1);
        $item = $this->item($b1, $o1);
        $this->assertNotNull($item);
        $this->assertStringContainsString('through your Window poster.', $item['line']);
        $this->assertStringContainsString('Try the Counter poster: By the register, where people pay.', $item['line']);
        $this->assertSame('Print the counter poster', $item['action_label']);
        $this->assertSame(route('owner.qr.poster', ['variant' => 'counter', 'print' => 1]), $item['url']);

        // Receipt proven, receipt printed: baseline {receipt},
        // so counter is the offer. (A window or table OFFER can
        // never render: reaching one requires counter + receipt
        // already placed, which closes the firing gate — those
        // offers are pinned on the order function above.)
        $b2 = World::business();
        $o2 = World::owner($b2);
        $r2 = $this->member($b2);
        $this->scan($b2, $r2, 'receipt', now()->subDays(5));
        $this->printAt($b2, $o2, 'receipt', now()->subDays(4));
        $this->visit($r2, 1);
        $item2 = $this->item($b2, $o2);
        $this->assertNotNull($item2);
        $this->assertSame('Print the counter poster', $item2['action_label']);
    }

    // ---------- Satisfied (L1; Section 6.4 items 1-2) ----------

    public function test_all_four_printed_before_settlement_never_renders(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        foreach (['counter', 'window', 'tent', 'receipt'] as $variant) {
            $this->printAt($business, $owner, $variant, now()->subDays(4));
        }
        $this->visit($referrer, 1);

        $this->assertNull($this->pageItem($business, $owner));
        $this->assertSame(SecondPlacementPrompt::STATE_SATISFIED, $this->promptState($business)?->state);

        $this->at('2026-10-29 09:00:00');
        $this->assertNull($this->item($business, $owner));
        $this->at('2026-11-10 09:00:00');
        $this->assertNull($this->item($business, $owner));
        $this->visit($referrer, 0);
        $this->assertNull($this->item($business, $owner));
    }

    public function test_exactly_two_printed_before_settlement_never_renders(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $this->printAt($business, $owner, 'counter', now()->subDays(4));
        $this->printAt($business, $owner, 'window', now()->subDays(4));
        $this->visit($referrer, 1);

        $this->assertNull($this->pageItem($business, $owner));
        $this->assertSame(SecondPlacementPrompt::STATE_SATISFIED, $this->promptState($business)?->state);
    }

    public function test_p1_alone_counts_as_placed_even_without_a_print_record(): void
    {
        // The referrer scanned the window code, so the window
        // placement IS out in the world; with a counter print
        // the placed count is already 2 at settlement.
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $this->scan($business, $referrer, 'window', now()->subDays(5));
        $this->printAt($business, $owner, 'counter', now()->subDays(4));
        $this->visit($referrer, 1);

        $this->assertNull($this->item($business, $owner));
    }

    // ---------- Held at first (L2/L2a/L2b; Section 6.4 item 3) ----------

    private function heldVisit(Membership $referrer, float $daysAgo = 1): Referral
    {
        return $this->visit($referrer, $daysAgo, [
            'coverage' => 'held',
            'reward' => ['hold' => true, 'held_at' => now()->subDays($daysAgo), 'state' => 'pending_vesting', 'available_at' => now()->addDays(13)],
        ]);
    }

    public function test_held_first_arms_silently_and_fires_at_release(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $this->scan($business, $referrer, 'counter', now()->subDays(5));
        $referral = $this->heldVisit($referrer, 1);

        // No row at redemption, and none while the hold ages.
        $this->assertNull($this->pageItem($business, $owner));
        $this->assertSame(SecondPlacementPrompt::STATE_ARMED, $this->promptState($business)?->state);
        for ($day = 1; $day <= 30; $day++) {
            Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addDays($day));
            $this->assertNull($this->item($business, $owner), "held day {$day} must stay silent");
        }

        // Release at day 30: the prompt fires off the RELEASE
        // settlement, and its 14-day clock starts there.
        $reward = $referral->reward;
        app(RedemptionService::class)->releaseReward($reward);
        $item = $this->pageItem($business, $owner);
        $this->assertNotNull($item, 'the row fires at the release settlement');
        $state = $this->promptState($business);
        $this->assertSame(SecondPlacementPrompt::STATE_LIVE, $state?->state);
        $this->assertTrue($state->settled_at->equalTo(now()), 'settled_at is the release instant, not redemption');

        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addDays(30 + 13));
        $this->assertNotNull($this->item($business, $owner), 'release + 13 days still renders');
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addDays(30 + 15));
        $this->assertNull($this->item($business, $owner), 'release + 15 days is expired');
    }

    public function test_held_first_denied_never_fires_and_a_later_settled_visit_fires_once(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $referral = $this->heldVisit($referrer, 2);

        $this->assertNull($this->item($business, $owner));
        app(RedemptionService::class)->denyReward($referral->reward);
        $this->assertNull($this->pageItem($business, $owner));

        // A later visit settles clean: it is the first settled
        // visit, and the prompt fires off it.
        $this->at('2026-11-05 09:00:00');
        $second = $this->visit($referrer, 1);
        $item = $this->pageItem($business, $owner);
        $this->assertNotNull($item);
        $this->assertSame($second->id, $this->promptState($business)?->trigger_referral_id);
    }

    public function test_first_means_first_settled_not_first_redeemed(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $heldReferrer = $this->member($business);
        $cleanReferrer = $this->member($business);
        $this->scan($business, $cleanReferrer, 'window', now()->subDays(6));

        // First redeemed is held; second redeemed settles.
        $this->heldVisit($heldReferrer, 3);
        $clean = $this->visit($cleanReferrer, 2);

        $item = $this->pageItem($business, $owner);
        $this->assertNotNull($item);
        $this->assertSame($clean->id, $this->promptState($business)?->trigger_referral_id);
        $this->assertStringContainsString('through your Window poster.', $item['line']);

        // Releasing the older held visit afterwards does not
        // move the trigger: the first SETTLED visit stands.
        $held = Referral::query()->where('business_id', $business->id)
            ->where('referrer_membership_id', $heldReferrer->id)->firstOrFail();
        app(RedemptionService::class)->releaseReward($held->reward);
        $itemAfter = $this->pageItem($business, $owner);
        $this->assertNotNull($itemAfter);
        $this->assertSame($clean->id, $this->promptState($business)?->trigger_referral_id);
    }

    // ---------- Terminals: acted, gap-closed, dismissed, expired ----------

    public function test_printing_the_suggested_placement_acts_and_ends_the_prompt(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $this->scan($business, $referrer, 'counter', now()->subDays(5));
        $this->printAt($business, $owner, 'counter', now()->subDays(4));
        $this->visit($referrer, 1);
        $this->assertNotNull($this->pageItem($business, $owner));

        // The owner prints the suggested receipt card through
        // the shipped poster route (the print event, not the
        // click's origin, is the signal).
        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'receipt', 'print' => 1]))->assertOk();

        $this->assertNull($this->pageItem($business, $owner));
        $state = $this->promptState($business);
        $this->assertSame(SecondPlacementPrompt::STATE_ACTED, $state?->state);
        $this->assertSame('receipt', $state?->acted_placement);

        // Never again, whatever later visits do.
        $this->visit($referrer, 0);
        $this->assertNull($this->item($business, $owner));
    }

    public function test_an_organic_second_print_closes_the_gap_without_prompt_credit(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $this->scan($business, $referrer, 'counter', now()->subDays(5));
        $this->visit($referrer, 1);

        // No Action Center visit at all: the owner prints a
        // DIFFERENT second placement from the QR page.
        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'window', 'print' => 1]))->assertOk();

        $this->assertNull($this->pageItem($business, $owner));
        $this->assertSame(SecondPlacementPrompt::STATE_GAP_CLOSED, $this->promptState($business)?->state);
    }

    public function test_dismissal_is_terminal_even_when_the_fingerprint_would_change(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $first = $this->visit($referrer, 1);
        $this->assertNotNull($this->pageItem($business, $owner));

        // Dismiss through the shipped route, by position.
        $items = app(ActionCenter::class)->items($business, $owner);
        $index = null;
        foreach ($items as $i => $item) {
            if ($item['key'] === 'second_placement') {
                $index = $i;
            }
        }
        $this->assertNotNull($index);
        $this->actingAs($owner)->post(route('actions.dismiss', $index))->assertRedirect(route('actions.index'));
        $this->assertSame(SecondPlacementPrompt::STATE_DISMISSED, $this->promptState($business)?->state);
        $this->assertNull($this->item($business, $owner));

        // Later settled visits (2nd, 5th, 20th) never resurface it.
        for ($i = 0; $i < 19; $i++) {
            $this->visit($referrer, 0);
            $this->assertNull($this->item($business, $owner), 'dismissed prompt resurfaced after a later visit');
        }

        // A fingerprint change (the trigger visit refunded, a
        // new visit settling) does not resurface it either:
        // the terminal stamp governs, not the fingerprint.
        BillingEvent::query()->where('referral_id', $first->id)->update(['coverage' => 'refunded']);
        $this->visit($referrer, 0);
        $this->assertNull($this->pageItem($business, $owner));
    }

    public function test_expiry_is_terminal_even_if_never_shown(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $this->visit($referrer, 1);

        // Boundary: settled + 14 days exactly still renders;
        // one second past, it does not.
        $settledAt = now()->subDay();
        Carbon::setTestNow($settledAt->copy()->addDays(14));
        $this->assertNotNull($this->item($business, $owner), 'day 14 exactly is inside the window');
        Carbon::setTestNow($settledAt->copy()->addDays(14)->addSecond());
        $this->assertNull($this->pageItem($business, $owner));
        $this->assertSame(SecondPlacementPrompt::STATE_EXPIRED, $this->promptState($business)?->state);

        foreach ([15, 30, 90] as $day) {
            Carbon::setTestNow($settledAt->copy()->addDays($day));
            $this->assertNull($this->item($business, $owner), "day {$day} must stay expired");
        }
        $this->visit($referrer, 0);
        $this->assertNull($this->item($business, $owner));
    }

    // ---------- Refund edges (L6 / L4-stands) ----------

    public function test_refund_before_any_action_unfires_without_consuming(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $first = $this->visit($referrer, 2);
        $this->assertNotNull($this->pageItem($business, $owner));

        BillingEvent::query()->where('referral_id', $first->id)->update(['coverage' => 'refunded']);
        $this->assertNull($this->item($business, $owner));
        $state = $this->promptState($business);
        $this->assertTrue($state === null || ! $state->isTerminal(), 'a pre-action refund must not consume the prompt');

        // A later visit settles: the feature fires off it, once.
        $second = $this->visit($referrer, 0);
        $this->assertNotNull($this->pageItem($business, $owner));
        $this->assertSame($second->id, $this->promptState($business)?->trigger_referral_id);
    }

    public function test_refund_after_acted_keeps_the_acted_terminal(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $this->scan($business, $referrer, 'counter', now()->subDays(5));
        $first = $this->visit($referrer, 1);
        $this->assertNotNull($this->pageItem($business, $owner));
        $this->actingAs($owner)->get(route('owner.qr.poster', ['variant' => 'receipt', 'print' => 1]))->assertOk();
        // The stamp lands at the next evaluating read (the
        // print event is the signal; the read derives ACTED).
        $this->assertNull($this->pageItem($business, $owner));
        $this->assertSame(SecondPlacementPrompt::STATE_ACTED, $this->promptState($business)?->state);

        BillingEvent::query()->where('referral_id', $first->id)->update(['coverage' => 'refunded']);
        $this->assertNull($this->pageItem($business, $owner));
        $this->assertSame(SecondPlacementPrompt::STATE_ACTED, $this->promptState($business)?->state, 'paper is not clawed back');
    }

    // ---------- Stand-down (L7; Section 6.4 item 9) ----------

    public function test_paused_and_closed_businesses_stand_down_and_return_inside_the_window(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $this->visit($referrer, 1);
        $this->assertNotNull($this->item($business, $owner));

        // Past-due, grace ended (episode 10 days ago, grace 7):
        // the flow is paused and the row stands down.
        $this->auditAt($business, $owner, 'billing.past_due', null, now()->subDays(10));
        $business->forceFill(['billing_status' => 'past_due'])->save();
        $this->assertNull($this->item($business->fresh(), $owner), 'past-due-paused stands down');

        // Recovery inside the window: the row returns.
        $business->forceFill(['billing_status' => 'active'])->save();
        $this->assertNotNull($this->item($business->fresh(), $owner), 'reactivation inside the window returns the row');

        // Closed stands down too.
        $business->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $this->assertNull($this->item($business->fresh(), $owner), 'closed stands down');

        // The clock never extended: past settlement + 14 days
        // the row is gone even after reopening.
        $business->forceFill(['status' => 'active', 'closed_at' => null])->save();
        Carbon::setTestNow(now()->addDays(15));
        $this->assertNull($this->item($business->fresh(), $owner), 'expiry is measured from the settlement instant');
    }

    public function test_past_due_in_grace_still_renders(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referrer = $this->member($business);
        $this->visit($referrer, 1);

        // Episode 2 days ago: inside the 7-day grace, the flow
        // still works, so the row is NOT stood down (L7 names
        // the pause, not the episode).
        $this->auditAt($business, $owner, 'billing.past_due', null, now()->subDays(2));
        $business->forceFill(['billing_status' => 'past_due'])->save();

        $this->assertNotNull($this->item($business->fresh(), $owner));
    }

    // ---------- Shown stamps (Section 8.1 item 4) ----------

    public function test_shown_stamps_land_once_per_day_and_only_on_the_page_read(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->visit($this->member($business), 1);

        // Badge/dashboard reads stamp nothing.
        $this->item($business, $owner);
        $this->assertSame(0, SecondPlacementPromptImpression::query()->where('business_id', $business->id)->count());

        $this->pageItem($business, $owner);
        $this->pageItem($business, $owner);
        $this->assertSame(1, SecondPlacementPromptImpression::query()->where('business_id', $business->id)->count());

        Carbon::setTestNow(now()->addDay());
        $this->pageItem($business, $owner);
        $this->assertSame(2, SecondPlacementPromptImpression::query()->where('business_id', $business->id)->count());
    }
}
