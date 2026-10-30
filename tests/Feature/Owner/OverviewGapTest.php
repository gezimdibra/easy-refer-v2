<?php

namespace Tests\Feature\Owner;

use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\ReferralEvent;
use App\Models\Reward;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Post-r26 gap lane B1 (design pack 01-overview, copy Amendment
 * 29 OV-1 to OV-3): the Overview recomposition for the standing
 * state. OV-1 chart axes, OV-2 today summary line, OV-3 standing
 * section order (zero and mid-setup orders unchanged), OV-4 the
 * "All time" heading and the New customers window caption, OV-5
 * the finished-checklist slim row, OV-6 weekly top referrers as
 * R1 row grammar. Every render here must stay inside the
 * dashboard query budget (DashboardPerformanceTest's cap of 40;
 * the leg runs at 39): the OV units add no queries.
 */
class OverviewGapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A business with every checklist step done (the own-full
     * standing state): card on file, poster downloaded, the
     * owner joined their own program, review link set, rewards
     * set and email verified by the World defaults.
     *
     * @return array{0: \App\Models\Business, 1: \App\Models\User}
     */
    private function standingBusiness(): array
    {
        $business = World::business([
            'google_review_url' => 'https://www.google.com/maps/place/example',
        ]);
        $owner = World::owner($business);
        // The World owner's verified stamp does not survive mass
        // assignment; the checklist reads hasVerifiedEmail().
        $owner->forceFill(['email_verified_at' => now()])->save();

        PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_ov_1',
            'card_brand' => 'VISA',
            'last4' => '5858',
            'exp_month' => 3,
            'exp_year' => 2028,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_ov_'.$business->id,
        ]);
        ReferralEvent::create([
            'business_id' => $business->id,
            'event_type' => 'poster_downloaded',
            'actor' => 'owner',
        ]);
        World::membership($business, World::customer($owner->phone_e164, 'Owner', 'Self'));

        return [$business, $owner];
    }

    /**
     * Mid-setup (the own-s2a shape): poster downloaded and the
     * owner has joined, but the card and review link steps are
     * still open, so the legacy DG-2 order must hold.
     *
     * @return array{0: \App\Models\Business, 1: \App\Models\User}
     */
    private function midSetupBusiness(): array
    {
        $business = World::business();
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();

        ReferralEvent::create([
            'business_id' => $business->id,
            'event_type' => 'poster_downloaded',
            'actor' => 'owner',
        ]);
        // RE-DERIVED (activation redesign, census C-24,
        // 2026-10-30): this fixture used to stay mid-setup
        // because the CARD step was open. The card is optional
        // now, so email + rewards + poster + a self membership
        // would COMPLETE the required set (4 of 4) and the
        // dashboard would render the standing state. The
        // membership seeded here is therefore a regular
        // customer's (it populates the dashboard's data
        // sections), not the owner's own: the open required
        // step is the test referral (3 of 4 done).
        World::membership($business, World::customer('+12125550299', 'Regular', 'Customer'));

        return [$business, $owner];
    }

    private function redeemedToday(\App\Models\Business $business, string $phone, string $first, string $last): void
    {
        [$membership] = World::membership($business, World::customer($phone, $first, $last));
        World::referral($membership, World::customer($phone.'9', 'Friend', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now(),
        ]);
    }

    // OV-1: axis ticks, gridlines, end-of-line value, aria-label.

    public function test_chart_renders_axis_ticks_gridlines_and_end_value_when_total_positive(): void
    {
        [$business, $owner] = $this->standingBusiness();
        for ($i = 0; $i < 5; $i++) {
            $this->redeemedToday($business, '+1212555020'.$i, 'Ref'.$i, 'Person');
        }

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-chart-tick="0"', $html);
        $this->assertStringContainsString('data-chart-tick="max"', $html);
        // Max is 5, at or above 4, so the mid tick renders too.
        $this->assertStringContainsString('data-chart-tick="mid"', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'data-chart-gridline'));
        $this->assertMatchesRegularExpression('/data-chart-end-value[^>]*>5<\/text>/', $html);
        $this->assertStringContainsString('maximum 5', $html);
        // The table alternative and range links stay.
        $response->assertSee('View as table');
        $response->assertSee('7 days');
    }

    public function test_chart_mid_tick_absent_below_four_and_ticks_absent_at_zero_total(): void
    {
        [$business, $owner] = $this->standingBusiness();
        $this->redeemedToday($business, '+12125550211', 'Ana', 'Ray');
        $this->redeemedToday($business, '+12125550212', 'Ben', 'Fox');

        $html = $this->actingAs($owner)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('data-chart-tick="max"', $html);
        $this->assertStringNotContainsString('data-chart-tick="mid"', $html);

        // Enrollments exist but nothing has completed: the chart
        // card shows its empty line and no ticks at all.
        $empty = World::business();
        $emptyOwner = World::owner($empty);
        World::membership($empty, World::customer('+12125550213', 'Cal', 'Doe'));

        $emptyHtml = $this->actingAs($emptyOwner)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('Your first visits will draw this line.', $emptyHtml);
        $this->assertStringNotContainsString('data-chart-tick', $emptyHtml);
    }

    // OV-2: the date and today summary line, standing state only.

    public function test_summary_line_matches_the_today_tiles_on_the_same_render(): void
    {
        [$business, $owner] = $this->standingBusiness();
        ReferralEvent::create(['business_id' => $business->id, 'event_type' => 'scan', 'actor' => 'customer']);
        ReferralEvent::create(['business_id' => $business->id, 'event_type' => 'scan', 'actor' => 'customer']);
        $this->redeemedToday($business, '+12125550221', 'Ana', 'Ray');

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();

        $tiles = $response->viewData('tiles');
        $date = Carbon::now('America/New_York')->format('l, F j');
        $expected = $date.'. '.$tiles['scans_today'].' scans today, '.$tiles['referrals_today'].' referrals completed.';
        $this->assertSame(2, $tiles['scans_today']);
        $this->assertSame(1, $tiles['referrals_today']);
        $response->assertSee($expected);
        $this->assertStringContainsString('data-ov-summary', $response->getContent());
    }

    public function test_summary_line_zero_form_and_absence_outside_standing_state(): void
    {
        [$business, $owner] = $this->standingBusiness();
        // A completion three days ago: standing, but today is zero.
        [$membership] = World::membership($business, World::customer('+12125550231', 'Ana', 'Ray'));
        World::referral($membership, World::customer('+121255502319', 'Fay', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(3),
        ]);

        $date = Carbon::now('America/New_York')->format('l, F j');
        $this->actingAs($owner)->get(route('dashboard'))
            ->assertSee($date.'. No scans yet today.');

        // Mid-setup and zero states carry no summary line.
        [, $midOwner] = $this->midSetupBusiness();
        $this->actingAs($midOwner)->get(route('dashboard'))
            ->assertDontSee('data-ov-summary', false);

        $zero = World::business();
        $this->actingAs(World::owner($zero))->get(route('dashboard'))
            ->assertDontSee('data-ov-summary', false);
    }

    // OV-3: section order per state.

    public function test_dom_order_zero_state_is_the_legacy_order(): void
    {
        $zero = World::business();
        $html = $this->actingAs(World::owner($zero))->get(route('dashboard'))->getContent();

        $checklist = strpos($html, 'Setup checklist');
        $tiles = strpos($html, 'Scans today');
        $value = strpos($html, 'What Easy Refer brought you');
        $this->assertNotFalse($checklist);
        $this->assertNotFalse($tiles);
        $this->assertNotFalse($value);
        $this->assertLessThan($tiles, $checklist);
        $this->assertLessThan($value, $tiles);
        $this->assertStringNotContainsString('data-ov-counters-heading', $html);
    }

    public function test_dom_order_mid_setup_is_the_legacy_order(): void
    {
        [, $owner] = $this->midSetupBusiness();
        $html = $this->actingAs($owner)->get(route('dashboard'))->getContent();

        $tiles = strpos($html, 'Scans today');
        $checklist = strpos($html, 'Setup checklist');
        $counters = strpos($html, 'data-ov-counters-heading');
        $chart = strpos($html, 'Cumulative referred customers');
        $referrers = strpos($html, 'Weekly top referrers');
        $value = strpos($html, 'What Easy Refer brought you');
        foreach ([$tiles, $checklist, $counters, $chart, $referrers, $value] as $pos) {
            $this->assertNotFalse($pos);
        }
        // DG-2: tiles, then the checklist, then counters, chart,
        // top referrers, and the Value panel last.
        $this->assertLessThan($checklist, $tiles);
        $this->assertLessThan($counters, $checklist);
        $this->assertLessThan($chart, $counters);
        $this->assertLessThan($referrers, $chart);
        $this->assertLessThan($value, $referrers);
    }

    public function test_dom_order_standing_state_is_the_recomposed_order(): void
    {
        [$business, $owner] = $this->standingBusiness();
        [$membership] = World::membership($business, World::customer('+12125550241', 'Ana', 'Ray'));
        [$redeemed] = World::referral($membership, World::customer('+121255502419', 'Fay', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        World::reward($membership, $redeemed, ['state' => Reward::STATE_AVAILABLE]);

        $html = $this->actingAs($owner)->get(route('dashboard'))->getContent();

        $summary = strpos($html, 'data-ov-summary');
        $needs = strpos($html, 'data-ov-needs');
        $tiles = strpos($html, 'Scans today');
        $chart = strpos($html, 'Cumulative referred customers');
        $value = strpos($html, 'What Easy Refer brought you');
        $counters = strpos($html, 'data-ov-counters-heading');
        $referrers = strpos($html, 'Weekly top referrers');
        $complete = strpos($html, 'Setup complete.');
        foreach ([$summary, $needs, $tiles, $chart, $value, $counters, $referrers, $complete] as $pos) {
            $this->assertNotFalse($pos);
        }
        $this->assertLessThan($needs, $summary);
        $this->assertLessThan($tiles, $needs);
        $this->assertLessThan($chart, $tiles);
        $this->assertLessThan($value, $chart);
        $this->assertLessThan($counters, $value);
        $this->assertLessThan($referrers, $counters);
        $this->assertLessThan($complete, $referrers);
    }

    // OV-4: the heading exactly once, the window caption, and the
    // standing counter order.

    public function test_all_time_heading_once_caption_and_standing_counter_order(): void
    {
        [$business, $owner] = $this->standingBusiness();
        $html = $this->actingAs($owner)->get(route('dashboard'))->getContent();

        $this->assertSame(1, substr_count($html, 'data-ov-counters-heading'));
        $this->assertStringContainsString('In the last 5 days', $html);
        $this->assertStringNotContainsString('<p class="tile-delta">Last 5 days</p>', $html);

        $region = substr($html, strpos($html, 'data-ov-counters-heading'), strpos($html, 'Weekly top referrers') - strpos($html, 'data-ov-counters-heading'));
        $order = ['Referrals completed', 'Total customers', 'New customers', 'Pending referrals', 'Rewards issued', 'Rewards claimed', 'Promotional messages sent'];
        $positions = array_map(fn ($label) => strpos($region, $label), $order);
        foreach ($positions as $pos) {
            $this->assertNotFalse($pos);
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, array_values($positions));
    }

    // OV-5: the finished-checklist slim row.

    public function test_standing_renders_slim_row_and_no_checklist_steps(): void
    {
        [, $owner] = $this->standingBusiness();
        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertSee('Setup complete.');
        $response->assertSee('You can reopen the checklist in Settings.');
        $response->assertDontSee('Setup checklist');
        $response->assertDontSee('of 5 required steps done');

        // Dismissal hides the slim row too (same dismissal domain).
        $this->actingAs($owner)->post(route('dashboard.checklist.dismiss'));
        $this->actingAs($owner)->get(route('dashboard'))->assertDontSee('Setup complete.');
    }

    public function test_mid_setup_renders_the_full_checklist_as_today(): void
    {
        [, $owner] = $this->midSetupBusiness();
        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertSee('Setup checklist');
        $response->assertDontSee('Setup complete.');
    }

    // OV-6: top referrers as R1 rows.

    public function test_top_referrers_render_as_rows_with_registered_meta_and_pill(): void
    {
        [$business, $owner] = $this->standingBusiness();
        [$membership] = World::membership($business, World::customer('+12125550251', 'Grace', 'Lee'));
        World::referral($membership, World::customer('+121255502519', 'Fay', 'One'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);

        $html = $this->actingAs($owner)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('Grace Lee', $html);
        $this->assertStringContainsString('Registered '.$membership->created_at->format('M j, Y'), $html);
        $this->assertStringContainsString('Active', $html);
        $this->assertStringNotContainsString('tbl-stack', $html);
    }

    // The query budget: a standing render stays within the cap
    // DashboardPerformanceTest enforces at volume.

    public function test_standing_dashboard_render_stays_inside_the_query_budget(): void
    {
        [$business, $owner] = $this->standingBusiness();
        $this->redeemedToday($business, '+12125550261', 'Ana', 'Ray');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(40, $count);
    }

    // r26 Gate 3 MINOR: the customer detail stat strip labels.

    public function test_customer_detail_stat_labels_use_the_compact_treatment(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550271', 'Ana', 'Ray'));

        $html = $this->actingAs($owner)->get(route('customers.show', $membership))->getContent();
        $this->assertSame(3, substr_count($html, 'tile-label-compact'));
        $this->assertStringContainsString('Referrals made', $html);
        $this->assertStringContainsString('Rewards earned', $html);
    }

    /**
     * The geometry half of the MINOR pin (the ActionCenter
     * geometry pattern): the real rendered customer page, the
     * real built CSS, WebKit at 375 and 390 in both color
     * schemes; no stat label may overflow its tile.
     */
    public function test_customer_detail_stat_labels_do_not_overflow_at_phone_widths(): void
    {
        $rig = getenv('POSTER_RIG_DIR') ?: getenv('HOME').'/workspace/webkit-spike';
        if (! is_dir($rig.'/node_modules/playwright') || ! is_dir($rig.'/browsers')) {
            $this->markTestSkipped('WebKit rig not available at '.$rig.'; customer stat geometry is unmeasured on this machine.');
        }
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node not on PATH; customer stat geometry is unmeasured.');
        }
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
        if ($cssFile === null) {
            $this->markTestSkipped('Build manifest has no app stylesheet; customer stat geometry is unmeasured.');
        }

        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550272', 'Ana', 'Ray'));

        $dir = sys_get_temp_dir().'/customer-stat-'.getmypid();
        @mkdir($dir, 0777, true);

        $html = $this->actingAs($owner)->get(route('customers.show', $membership))->assertOk()->getContent();
        $origin = rtrim((string) config('app.url'), '/');
        $html = str_replace($origin, '', $html);
        $html = str_replace('</head>', '<link rel="stylesheet" href="/build/'.$cssFile.'"></head>', $html);
        file_put_contents($dir.'/customer.html', $html);
        file_put_contents($dir.'/config.json', json_encode([
            'pagesDir' => $dir,
            'publicDir' => public_path(),
            'rigDir' => $rig,
            'widths' => [375, 390],
        ]));

        $cmd = 'POSTER_RIG_DIR='.escapeshellarg($rig)
            .' PLAYWRIGHT_BROWSERS_PATH='.escapeshellarg($rig.'/browsers')
            .' timeout 120 '.$node.' '.escapeshellarg(__DIR__.'/../../Support/customer-stat-probe.mjs')
            .' '.escapeshellarg($dir.'/config.json').' 2>/dev/null';
        $decoded = json_decode((string) shell_exec($cmd), true);
        if (! is_array($decoded)) {
            $this->fail('Customer stat probe produced no measurement. Command: '.$cmd);
        }

        foreach (['375-light', '390-light', '375-dark', '390-dark'] as $key) {
            $this->assertArrayHasKey($key, $decoded, "No probe measurement at {$key}.");
            $labels = $decoded[$key]['labels'];
            // Anti-vacuity: the strip really rendered three labels.
            $this->assertCount(3, $labels, "Stat strip did not render three labels at {$key}.");
            foreach ($labels as $label) {
                $this->assertLessThanOrEqual(
                    $label['clientW'] + 1,
                    $label['scrollW'],
                    "At {$key} the label \"{$label['text']}\" scrolls {$label['scrollW']}px in a {$label['clientW']}px box."
                );
                $this->assertLessThanOrEqual(
                    $label['tileRight'] + 0.5,
                    $label['right'],
                    "At {$key} the label \"{$label['text']}\" reaches past its tile's right edge."
                );
                $this->assertGreaterThanOrEqual(
                    $label['tileLeft'] - 0.5,
                    $label['left'],
                    "At {$key} the label \"{$label['text']}\" reaches past its tile's left edge."
                );
            }
        }
    }
}
