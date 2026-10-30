<?php

namespace Tests\Feature\Owner;

use App\Models\Referral;
use App\Models\Reward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;
use Tests\TestCase;

/**
 * R1 data pages redesign (portal build plan P5): the shared
 * units (TAB-SCROLL, PAGE-BAND), the Customers / Referrals /
 * Rewards row grammars, and the detail treatments. Every list
 * carries a query-count pin: rendering more rows must not run
 * more queries (no per-row query growth).
 */
class R1DataPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(\App\Contracts\SmsGateway::class, new \Tests\Fakes\FakeSmsGateway);
    }

    /**
     * Count the queries one GET runs, with the query log scoped
     * to the request itself (the DashboardPerformanceTest
     * pattern).
     */
    private function queriesFor(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function seedCustomers($business, int $count, int $phoneStart): void
    {
        for ($i = 0; $i < $count; $i++) {
            $phone = '+1212555'.str_pad((string) ($phoneStart + $i), 4, '0', STR_PAD_LEFT);
            World::membership($business, World::customer($phone, 'Cust'.$i, 'Test'));
        }
    }

    // TAB-SCROLL (RF-1 = RW-5a): filter tabs never wrap below md;
    // they scroll horizontally in one row instead.

    public function test_filter_tabs_scroll_in_one_row_and_never_wrap(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        foreach ([route('referrals.index'), route('rewards.index')] as $url) {
            $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('flex flex-nowrap gap-2 overflow-x-auto', $html);
            $this->assertStringNotContainsString('role="tablist" class="flex flex-wrap', $html);
        }
    }

    // PAGE-BAND (CU-4 = RF-4 = RW-5b): the pagination band only
    // renders when there are pagination links to show.

    public function test_pagination_band_is_absent_on_a_single_page(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550601', 'Ana', 'Ray'));
        [$referral] = World::referral($membership, World::customer('+12125550602', 'Fay', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        World::reward($membership, $referral);

        foreach ([route('customers.index'), route('referrals.index'), route('rewards.index')] as $url) {
            $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('border-t border-line px-4 py-3', $html, $url);
            $this->assertStringNotContainsString('Pagination Navigation', $html, $url);
        }
    }

    public function test_pagination_band_renders_when_there_are_multiple_pages(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->seedCustomers($business, 26, 6100);

        $html = $this->actingAs($owner)->get(route('customers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('border-t border-line px-4 py-3', $html);
        $this->assertStringContainsString('Pagination Navigation', $html);
    }

    // Customers CU-1..CU-3: mobile row grammar (monogram, name,
    // meta line, texts pill), owner-asserted line in warning
    // tone at 550 weight on both form factors, desktop table
    // tightened with the monogram in the name cell. The stacked
    // table leaves this page.

    public function test_customers_mobile_rows_carry_the_meta_line_and_monogram(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550611', 'Dana', 'Whitfield'));
        $referrals = [];
        foreach (['Chris', 'Leo', 'Mia'] as $i => $friend) {
            [$referrals[]] = World::referral($membership, World::customer('+1212555062'.$i, $friend, 'Test'), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
            ]);
        }
        World::reward($membership, $referrals[0]);
        World::reward($membership, $referrals[1]);

        $response = $this->actingAs($owner)->get(route('customers.index'));
        $response->assertOk();
        $joined = $membership->created_at->format('M j, Y');
        $response->assertSee('3 referrals · 2 rewards · joined '.$joined);
        $response->assertSee('>DW<', false);
        $html = $response->getContent();
        $this->assertStringNotContainsString('tbl-stack', $html);
        $this->assertStringContainsString('md:hidden', $html);
        $this->assertStringContainsString('hidden overflow-x-auto md:block', $html);
    }

    public function test_customers_meta_line_singularizes_one_referral(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550631', 'Solo', 'Act'));
        World::referral($membership, World::customer('+12125550632', 'Fay', 'One'));

        $joined = $membership->created_at->format('M j, Y');
        $this->actingAs($owner)->get(route('customers.index'))
            ->assertSee('1 referral · 0 rewards · joined '.$joined)
            ->assertDontSee('1 referrals ·');
    }

    public function test_owner_asserted_line_renders_in_warning_tone(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550641', 'Ana', 'Ray'));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'owner_asserted'])->save();

        $html = $this->actingAs($owner)->get(route('customers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('text-warning-text', $html);
        $this->assertStringContainsString('Added by you, waiting for their confirmation', $html);
        // Both form factors carry the treatment (mobile row list
        // and desktop table), so the string appears twice with
        // the warning tone on both.
        $this->assertSame(2, substr_count($html, 'Added by you, waiting for their confirmation'));
    }

    public function test_customers_list_query_count_does_not_grow_with_rows(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->seedCustomers($business, 3, 6200);

        $few = $this->queriesFor(fn () => $this->actingAs($owner)->get(route('customers.index'))->assertOk());

        $this->seedCustomers($business, 6, 6300);
        $many = $this->queriesFor(fn () => $this->actingAs($owner)->get(route('customers.index'))->assertOk());

        // The invariant is no per-row growth: tripling the rows
        // must never add queries (an N+1 would add at least one
        // per extra row). A small downward drift is measurement
        // noise from one-off warm-up queries, not a regression.
        $this->assertLessThanOrEqual($few, $many, "customers list queries grew from {$few} to {$many} when rows tripled");
    }

    // Customer detail CD-1..CD-5: histories as row lists (no
    // squeezed tables, nothing to clip), a 3-tile stat strip in
    // the identity panel, the held-row treatment, quiet empty
    // sections, and the danger panel demoted.

    /**
     * @return array{business: mixed, owner: mixed, membership: mixed}
     */
    private function danaWorld(): array
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550651', 'Dana', 'Whitfield'));
        $made = [];
        foreach (['Chris', 'Leo', 'Mia'] as $i => $friend) {
            [$made[]] = World::referral($membership, World::customer('+1212555066'.$i, $friend, 'Test'), [
                'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
            ]);
        }
        World::reward($membership, $made[0]);
        World::reward($membership, $made[1]);
        // One visit: Dana is the friend on someone else's referral.
        [$referrer] = World::membership($business, World::customer('+12125550659', 'Rita', 'Referrer'));
        World::referral($referrer, $membership->customer, [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);

        return compact('business', 'owner', 'membership');
    }

    public function test_customer_detail_histories_are_row_lists_not_tables(): void
    {
        ['owner' => $owner, 'membership' => $membership] = $this->danaWorld();

        $html = $this->actingAs($owner)->get(route('customers.show', $membership))->assertOk()->getContent();
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString('Chris', $html);
        $this->assertStringContainsString('Rita', $html);
    }

    public function test_customer_detail_stat_strip_counts_from_the_pages_own_data(): void
    {
        ['owner' => $owner, 'membership' => $membership] = $this->danaWorld();

        $html = $this->actingAs($owner)->get(route('customers.show', $membership))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Referrals made<\/p>\s*<p class="tile-value">3<\/p>/', $html);
        $this->assertMatchesRegularExpression('/Rewards earned<\/p>\s*<p class="tile-value">2<\/p>/', $html);
        // CD-2's one ratified micro-copy: the tile label "Visits".
        $this->assertMatchesRegularExpression('/>Visits<\/p>\s*<p class="tile-value">1<\/p>/', $html);
    }

    public function test_customer_detail_held_referral_row_carries_reason_and_queue_link(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550671', 'Dana', 'Whitfield'));
        World::referral($membership, World::customer('+12125550672', 'Leo', 'Test'), [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now(),
        ]);

        $html = $this->actingAs($owner)->get(route('customers.show', $membership))->assertOk()->getContent();
        $this->assertStringContainsString('Held for review:', $html);
        $this->assertStringContainsString('Open the review queue', $html);
        $this->assertStringContainsString('border-warning-strong', $html);
    }

    public function test_customer_detail_empty_histories_are_quiet_single_lines(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550681', 'New', 'Person'));

        $html = $this->actingAs($owner)->get(route('customers.show', $membership))->assertOk()->getContent();
        $this->assertStringContainsString('No referrals yet.', $html);
        $this->assertStringContainsString('No rewards yet.', $html);
        $this->assertStringContainsString('No visits yet.', $html);
        $this->assertStringContainsString('padding-top: 16px', $html);
    }

    public function test_customer_detail_danger_panel_does_not_repeat_the_modal_explanation(): void
    {
        ['owner' => $owner, 'membership' => $membership] = $this->danaWorld();

        $html = $this->actingAs($owner)->get(route('customers.show', $membership))->assertOk()->getContent();
        // The explanation lives in the confirm modal only (CD-5).
        $this->assertSame(1, substr_count($html, 'This ends their connection to your store.'));
        $this->assertStringContainsString('Remove from your store', $html);
    }

    // Referrals RF-2 / RF-3: journey rows on mobile (friend,
    // meta line, one primary pill, labeled Reward line only
    // when a reward exists), the desktop Status merge (the
    // REWARD STATUS column is gone), held rows tinted with the
    // warning treatment on both form factors.

    /**
     * @return array{owner: mixed, redeemed: mixed, pending: mixed}
     */
    private function referralsWorld(): array
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550691', 'Dana', 'Whitfield'));
        [$redeemed] = World::referral($membership, World::customer('+12125550692', 'Chris', 'Test'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(2),
        ]);
        World::reward($membership, $redeemed);
        [$pending] = World::referral($membership, World::customer('+12125550693', 'Penny', 'Test'));
        World::referral($membership, World::customer('+12125550694', 'Hank', 'Test'), [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now(),
        ]);

        return compact('owner', 'redeemed', 'pending');
    }

    public function test_referrals_mobile_journey_rows_and_desktop_status_merge(): void
    {
        ['owner' => $owner, 'redeemed' => $redeemed, 'pending' => $pending] = $this->referralsWorld();

        $response = $this->actingAs($owner)->get(route('referrals.index'));
        $response->assertOk();
        $response->assertSee('by Dana · started '.$redeemed->created_at->format('M j, Y').' · visited '.$redeemed->redeemed_at->format('M j, Y'));
        $response->assertSee('by Dana · started '.$pending->created_at->format('M j, Y'));
        // "No reward yet" renders nothing: the absent Reward
        // line is the information (RF-2).
        $response->assertDontSee('No reward yet');
        $html = $response->getContent();
        $this->assertStringNotContainsString('tbl-stack', $html);
        $this->assertStringNotContainsString('Reward status', $html);
        $this->assertStringContainsString('<th scope="col">Status</th>', $html);
        $this->assertStringContainsString('bg-warning-fill', $html);
        $this->assertStringContainsString('Held for review:', $html);
    }

    public function test_referrals_list_query_count_does_not_grow_with_rows(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550701', 'Dana', 'Whitfield'));
        $seed = function (int $start, int $count) use ($membership) {
            for ($i = 0; $i < $count; $i++) {
                [$referral] = World::referral($membership, World::customer('+121255507'.str_pad((string) ($start + $i), 3, '0', STR_PAD_LEFT), 'Friend'.$i, 'Test'), [
                    'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
                ]);
                // A vesting reward exercises the readyOn path,
                // whose business read must be eager, not per row.
                World::reward($membership, $referral, [
                    'state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(3),
                ]);
            }
        };
        $seed(10, 3);
        $few = $this->queriesFor(fn () => $this->actingAs($owner)->get(route('referrals.index'))->assertOk());
        $seed(20, 6);
        $many = $this->queriesFor(fn () => $this->actingAs($owner)->get(route('referrals.index'))->assertOk());

        $this->assertLessThanOrEqual($few, $many, "referrals list queries grew from {$few} to {$many} when rows tripled");
    }

    // Referral detail RD-1..RD-5: people-first H1, labeled
    // status strip, the hold in a warning banner, timeline
    // nodes colored by event class with the rail ending at the
    // final node, Regenerate demoted behind Copy.

    public function test_referral_detail_header_names_the_people_and_labels_the_pills(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550721', 'Dana', 'Whitfield'));
        [$referral] = World::referral($membership, World::customer('+12125550722', 'Chris', 'Test'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        World::reward($membership, $referral);

        $html = $this->actingAs($owner)->get(route('referrals.show', $referral))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<h1[^>]*>Chris, referred by Dana<\/h1>/', $html);
        $this->assertStringContainsString('Started '.$business->localTime($referral->created_at)->format('M j, Y'), $html);
        $this->assertStringContainsString('>Referral</span>', $html);
        // RT-1: the reward captions name their owners; the bare
        // "Reward" caption is retired (see ReferralRewardLinesTest).
        $this->assertStringContainsString('>Dana&#039;s reward</span>', $html);
        $this->assertStringContainsString('>Chris&#039;s reward</span>', $html);
        $this->assertStringNotContainsString('>Reward</span>', $html);
        $this->assertStringNotContainsString('<h1 class="type-title-2 text-text-1">Referral detail</h1>', $html);
    }

    public function test_referral_detail_hold_lives_in_a_warning_banner(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550731', 'Dana', 'Whitfield'));
        [$held] = World::referral($membership, World::customer('+12125550732', 'Leo', 'Test'), [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now(),
        ]);
        [$plain] = World::referral($membership, World::customer('+12125550733', 'Penny', 'Test'));

        $heldHtml = $this->actingAs($owner)->get(route('referrals.show', $held))->assertOk()->getContent();
        $this->assertStringContainsString('banner banner-warning', $heldHtml);
        $this->assertStringContainsString('Held for review:', $heldHtml);
        $this->assertStringContainsString('Open the review queue', $heldHtml);

        $plainHtml = $this->actingAs($owner)->get(route('referrals.show', $plain))->assertOk()->getContent();
        $this->assertStringNotContainsString('banner banner-warning', $plainHtml);
    }

    public function test_referral_detail_timeline_nodes_are_colored_by_event_class_and_the_rail_ends(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550741', 'Dana', 'Whitfield'));
        [$referral] = World::referral($membership, World::customer('+12125550742', 'Chris', 'Test'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        \App\Models\ReferralEvent::record('referral_created', 'customer', $business, $referral, null, $membership);
        \App\Models\ReferralEvent::record('flag_raised', 'system', $business, $referral, null, $membership);
        \App\Models\ReferralEvent::record('redemption', 'customer', $business, $referral, null, $membership);

        $html = $this->actingAs($owner)->get(route('referrals.show', $referral))->assertOk()->getContent();
        $this->assertStringContainsString('bg-success-fill', $html);
        $this->assertStringContainsString('bg-warning-fill', $html);
        $this->assertStringContainsString('bg-surface-2', $html);
        // Three events, two rail segments: the rail ends at the
        // final node instead of dangling below it (RD-4).
        $this->assertSame(2, substr_count($html, 'timeline-rail'));
    }

    public function test_referral_detail_regenerate_is_demoted_behind_copy(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550751', 'Dana', 'Whitfield'));
        [$referral] = World::referral($membership, World::customer('+12125550752', 'Chris', 'Test'));

        $html = $this->actingAs($owner)->get(route('referrals.show', $referral))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/class="btn btn-ghost btn-sm"[^>]*>Regenerate redeem link<\/button>/', $html);
        $this->assertMatchesRegularExpression('/class="btn btn-secondary"[^>]*>.{0,400}Copy<\/button>/s', $html);
    }

    // Rewards RW-1..RW-4: reward-first mobile rows, the held
    // strip on both form factors, compact failed-text rows,
    // and the desktop merge (Claimed column folded into the
    // Status cell, reward cell semibold).

    public function test_rewards_rows_lead_with_the_reward_and_carry_their_states(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550761', 'Dana', 'Whitfield'));
        [$r1] = World::referral($membership, World::customer('+12125550762', 'Chris', 'Test'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(3),
        ]);
        World::reward($membership, $r1, [
            'state' => Reward::STATE_CLAIMED, 'claimed_at' => now()->subDays(2), 'notification_state' => 'delivered',
        ]);
        [$r2] = World::referral($membership, World::customer('+12125550763', 'Leo', 'Test'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        World::reward($membership, $r2, [
            'state' => Reward::STATE_AVAILABLE, 'notification_state' => 'failed',
        ]);
        [$r3] = World::referral($membership, World::customer('+12125550764', 'Mia', 'Test'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        World::reward($membership, $r3, [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now(),
        ]);

        $response = $this->actingAs($owner)->get(route('rewards.index'));
        $response->assertOk();
        // RW-1 meta line: customer, friend, the claimed date
        // only because this reward is claimed.
        $response->assertSee('Dana Whitfield · for Chris · claimed '.now()->subDays(2)->format('M j, Y'));
        $response->assertSee('Text: Delivered');
        // RW-3: the failed text is a compact caption plus the
        // small retry, never an exploded row.
        $response->assertSee('Text: Failed');
        $response->assertSee('Text again');
        // RW-2: the held reward explains itself and routes to
        // the review queue.
        $response->assertSee('Held for review:');
        $response->assertSee('Open the review queue');
        $html = $response->getContent();
        $this->assertStringNotContainsString('tbl-stack', $html);
        $this->assertStringNotContainsString('<th scope="col">Claimed</th>', $html);
        // RW-4: claimed date survives as the Status subline, and
        // the reward cell carries semibold weight on desktop.
        $this->assertStringContainsString('Claimed '.now()->subDays(2)->format('M j, Y'), $html);
        $this->assertMatchesRegularExpression('/<td class="font-semibold text-text-1">\$10 off<\/td>/', $html);
        $this->assertStringContainsString('border-warning-strong', $html);
    }

    public function test_rewards_list_query_count_does_not_grow_with_rows(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550771', 'Dana', 'Whitfield'));
        $seed = function (int $start, int $count) use ($membership) {
            for ($i = 0; $i < $count; $i++) {
                [$referral] = World::referral($membership, World::customer('+121255507'.str_pad((string) ($start + $i), 3, '0', STR_PAD_LEFT), 'Friend'.$i, 'Test'), [
                    'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
                ]);
                World::reward($membership, $referral, [
                    'state' => Reward::STATE_PENDING_VESTING, 'available_at' => now()->addDays(3),
                ]);
            }
        };
        $seed(10, 3);
        $few = $this->queriesFor(fn () => $this->actingAs($owner)->get(route('rewards.index'))->assertOk());
        $seed(20, 6);
        $many = $this->queriesFor(fn () => $this->actingAs($owner)->get(route('rewards.index'))->assertOk());

        $this->assertLessThanOrEqual($few, $many, "rewards list queries grew from {$few} to {$many} when rows tripled");
    }
}
