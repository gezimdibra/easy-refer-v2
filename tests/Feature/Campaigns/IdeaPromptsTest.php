<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\CampaignPromptEvent;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\SmsCampaign;
use App\Services\Campaigns\CampaignPrompts;
use App\Services\Owner\ActionCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ID-01 Phase 1 (owner-declared, Amendment 46) + ID-03 prompt:
 * ONE prompt infrastructure (event log, fingerprint dismissal,
 * per-item suppression policies) under two Action Center rows.
 * The slow-day prompt is the owner's own reminder for a day
 * THEY declared; the win-back prompt announces a standing
 * segment. Neither ever sends: both land on preset -> confirm.
 */
class IdeaPromptsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 8100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
        // A fixed Wednesday (business-local America/New_York).
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(Business $business): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        return $membership->fresh();
    }

    private function members(Business $business, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->member($business);
        }
    }

    private function lapsed(Business $business, int $daysAgo = 60): Membership
    {
        $m = $this->member($business);
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$referral] = World::referral($m, World::customer($phone));
        $referral->forceFill([
            'state' => Referral::STATE_REDEEMED,
            'created_at' => now()->subDays($daysAgo),
            'redeemed_at' => now()->subDays($daysAgo - 2),
        ])->save();
        BillingEvent::create([
            'business_id' => $business->id, 'referral_id' => $referral->id,
            'amount_cents' => 200, 'coverage' => 'billable',
        ]);

        return $m;
    }

/**
     * ACTIVATION REDESIGN fixture note (2026-10-30): these
     * prompt scenarios model established businesses whose
     * visits already fill the free allowance (5+ counted
     * BillingEvents). Under the allowance gate a cardless
     * business in that state is PAUSED and every growth
     * prompt stands down by design, so the fixtures carry
     * the card their billing history implies.
     */
    private function succeededCard(\App\Models\Business $business): void
    {
        \App\Models\PaymentMethod::create([
            'business_id' => $business->id, 'square_card_id' => 'card_prompt_1', 'card_brand' => 'Visa',
            'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030,
            'is_default' => true, 'state' => \App\Models\PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_prompt_'.$business->id,
        ]);
    }

    private function items(Business $business): array
    {
        $owner = World::owner($business);

        return collect(app(ActionCenter::class)->pageState($business, $owner)['items'])->keyBy('key')->all();
    }

    // ---------- ID-01: the owner tap + the quiet day ----------

    public function test_owner_tap_works_with_no_quiet_day_set_at_all(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->members($business, 3);
        $this->assertNull($business->slow_day_weekday);

        $this->actingAs($owner)->post(route('campaigns.preset'), ['preset' => 'slow_day']);
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();
        $this->assertSame('We have room today and would love to see you. Stop by, and bring a friend along: when they visit, you both get a reward.', $campaign->body);
        $this->assertNull($campaign->audience_segment);
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign))->assertOk();
    }

    public function test_quiet_day_card_saves_the_owners_pick(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('campaigns.create'))
            ->assertOk()
            ->assertSee('Your quiet day')
            ->assertSee('Pick the day your shop is usually quietest. On that day, when your program is active and enough customers can get texts, we will set up a ready-made text for your customers in your Action Center. You always review it before anything sends.')
            ->assertSee('No quiet day');

        $this->actingAs($owner)->post(route('campaigns.quiet-day'), ['slow_day_weekday' => 3])
            ->assertRedirect(route('campaigns.create'));
        $this->assertSame(3, $business->fresh()->slow_day_weekday);

        $this->actingAs($owner)->post(route('campaigns.quiet-day'), ['slow_day_weekday' => '']);
        $this->assertNull($business->fresh()->slow_day_weekday);
    }

    public function test_slow_day_prompt_fires_on_the_picked_day_with_the_owners_own_framing(): void
    {
        $business = World::business(['slow_day_weekday' => 3]); // Wednesday = today
        $this->members($business, 5);

        $items = $this->items($business);
        $this->assertArrayHasKey('slow_day_boost', $items);
        $this->assertSame(
            'Wednesday is the quiet day you picked. A ready-made text for your customers is ready to review.',
            $items['slow_day_boost']['line'],
        );
        $this->assertSame('Start the text', $items['slow_day_boost']['action_label']);
        $this->assertSame(80, $items['slow_day_boost']['urgency']);
        // The row never claims the day IS slow.
        $this->assertStringNotContainsString('slowest', $items['slow_day_boost']['line']);
        $this->assertStringNotContainsString('slow day', strtolower($items['slow_day_boost']['line']));

        // The Action Center page renders the prompt as a POST
        // form into the preset path (the click creates a draft,
        // it never sends).
        $this->actingAs(World::owner($business))->get(route('actions.index'))
            ->assertOk()
            ->assertSee('Wednesday is the quiet day you picked.');
    }

    public function test_slow_day_prompt_absent_on_other_days_and_without_a_pick(): void
    {
        $business = World::business(['slow_day_weekday' => 4]); // Thursday pick, today Wednesday
        $this->members($business, 6);
        $this->assertArrayNotHasKey('slow_day_boost', $this->items($business));

        $business->forceFill(['slow_day_weekday' => null])->save();
        $this->assertArrayNotHasKey('slow_day_boost', $this->items($business->fresh()));
    }

    public function test_slow_day_prompt_never_fires_past_due_even_in_grace(): void
    {
        $business = World::business(['slow_day_weekday' => 3, 'billing_status' => 'past_due']);
        $this->members($business, 6);
        // FLOW-STATE TRUTH train: "in grace" requires a datable
        // episode (a bare past_due with no evidence fails closed
        // to paused). Two-key Finding 1 fix (2026-10-30): the
        // episode evidence is the billing.past_due audit row —
        // the failed record alone is not episode-bound and no
        // longer dates anything — so the fixture writes the
        // real webhook shape (record + audit row). flowIsActive
        // is true in grace, yet G3 stands the prompt down.
        $record = \App\Models\BillingRecord::create([
            'business_id' => $business->id,
            'kind' => \App\Models\BillingRecord::KIND_PER_REFERRAL_BATCH,
            'state' => \App\Models\BillingRecord::STATE_FAILED,
            'amount_cents' => 1800,
            'currency' => 'USD',
            'idempotency_key' => 'idea-prompts-ingrace-slowday',
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
        ]);
        \App\Models\AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_ingrace_slowday'], $business, $record);
        $this->assertTrue($business->fresh()->flowIsActive());
        $this->assertArrayNotHasKey('slow_day_boost', $this->items($business->fresh()));
    }

    public function test_slow_day_prompt_absent_below_five_sendable_and_after_a_recent_send(): void
    {
        $business = World::business(['slow_day_weekday' => 3]);
        $owner = World::owner($business);
        $this->members($business, 4);
        $this->assertArrayNotHasKey('slow_day_boost', $this->items($business));

        $this->members($business, 2);
        $this->assertArrayHasKey('slow_day_boost', $this->items($business));

        // A campaign sent 3 days ago: G6 spacing stands it down
        // (travel to that Wednesday and re-check).
        SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Sale', 'status' => SmsCampaign::STATUS_SENT,
            'created_by' => $owner->id, 'sent_at' => now()->subDays(3),
        ]);
        $this->assertArrayNotHasKey('slow_day_boost', $this->items($business));
    }

    public function test_slow_day_dismissal_silences_four_candidate_weeks_then_reappears(): void
    {
        $business = World::business(['slow_day_weekday' => 3]);
        $owner = World::owner($business);
        $this->members($business, 5);
        $center = app(ActionCenter::class);

        $this->assertArrayHasKey('slow_day_boost', $this->items($business));
        $this->assertTrue($center->dismiss($business, $owner, 'slow_day_boost'));

        // The next 4 Wednesdays: silent. The 5th: back.
        for ($week = 1; $week <= 4; $week++) {
            Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks($week));
            $this->assertArrayNotHasKey('slow_day_boost', $this->items($business->fresh()), "week {$week} should be silent");
        }
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks(5));
        $this->assertArrayHasKey('slow_day_boost', $this->items($business->fresh()));
    }

    public function test_slow_day_second_dismissal_within_12_weeks_turns_reminders_off_until_reenabled(): void
    {
        $business = World::business(['slow_day_weekday' => 3]);
        $owner = World::owner($business);
        $this->members($business, 5);
        $center = app(ActionCenter::class);

        $center->dismiss($business, $owner, 'slow_day_boost');
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks(5));
        $this->assertArrayHasKey('slow_day_boost', $this->items($business->fresh()));
        $center->dismiss($business->fresh(), $owner, 'slow_day_boost');

        $this->assertTrue($business->fresh()->slow_day_reminders_off);
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks(20));
        $this->assertArrayNotHasKey('slow_day_boost', $this->items($business->fresh()));

        // The quiet-day card carries the off state + re-enable.
        $this->actingAs($owner)->get(route('campaigns.create'))
            ->assertOk()
            ->assertSee('Slow-day reminders are off.')
            ->assertSee('Turn reminders back on');
        $this->actingAs($owner)->post(route('campaigns.quiet-day'), ['slow_day_weekday' => 3, 'reminders_on' => 1]);
        $this->assertFalse($business->fresh()->slow_day_reminders_off);
    }

    public function test_three_ignored_slow_day_prompts_turn_reminders_off(): void
    {
        $business = World::business(['slow_day_weekday' => 3]);
        $this->members($business, 5);

        for ($week = 0; $week < 3; $week++) {
            Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks($week));
            $this->assertArrayHasKey('slow_day_boost', $this->items($business->fresh()));
        }
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks(3));
        $this->assertArrayNotHasKey('slow_day_boost', $this->items($business->fresh()));
        $this->assertTrue($business->fresh()->slow_day_reminders_off);
    }

    public function test_boost_send_resets_slow_day_suppression_counts(): void
    {
        $business = World::business(['slow_day_weekday' => 3]);
        $owner = World::owner($business);
        $this->members($business, 5);
        $center = app(ActionCenter::class);
        $center->dismiss($business, $owner, 'slow_day_boost');

        // A boost send (the owner tap path) clears the counts:
        // a dismissal event after the send starts fresh, so the
        // following Wednesday is NOT inside the old silence if
        // the send was after it... the send happens this week;
        // next Wednesday the prompt may fire again.
        $sent = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'We have room today and would love to see you. Stop by, and bring a friend along: when they visit, you both get a reward.',
            'status' => SmsCampaign::STATUS_SENT, 'created_by' => $owner->id,
            'origin' => 'slow_day_preset', 'sent_at' => now(),
        ]);
        CampaignPrompts::noteSent($sent);

        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks(1));
        // G6: a campaign was sent 7+ days ago by now (exactly 7
        // days: the spacing gate reads sent in the PREVIOUS 7
        // days; at exactly +7d it has cleared).
        $this->assertArrayHasKey('slow_day_boost', $this->items($business->fresh()));
    }

    public function test_slow_day_results_clause_appears_only_with_a_closed_window(): void
    {
        $business = World::business(['slow_day_weekday' => 3]);
        $owner = World::owner($business);
        $this->members($business, 5);
        $boost = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'We have room today and would love to see you. Stop by, and bring a friend along: when they visit, you both get a reward.',
            'status' => SmsCampaign::STATUS_SENT, 'created_by' => $owner->id,
            'origin' => 'slow_day_prompt', 'sent_at' => now()->subDays(6),
        ]);
        // At 6 days the G6 spacing gate still stands the prompt
        // itself down (a campaign was sent inside 7 days), so
        // no row — and therefore no clause — can render.
        $this->assertArrayNotHasKey('slow_day_boost', $this->items($business));

        $boost->forceFill(['sent_at' => now()->subDays(8)])->save();
        $line = $this->items($business->fresh())['slow_day_boost']['line'];
        $this->assertStringContainsString('Last time: 0 texts delivered, and 0 customers visited within 7 days of the text. That counts visits after the text; it does not prove the text caused them.', $line);
    }

    /**
     * Condition 3 (two-key): seed one redeemed visit (a
     * redeemed referral) for the analyzer floor fixture.
     */
    private function analyzerVisit(Business $business, Carbon $when): void
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $friendPhone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$referral] = World::referral($membership, World::customer($friendPhone));
        $referral->forceFill([
            'state' => Referral::STATE_REDEEMED,
            'created_at' => $when,
            'redeemed_at' => $when,
        ])->save();
    }

    public function test_analyzer_output_reaches_no_rendered_surface(): void
    {
        // Condition 3 (two-key): the fixture must PASS the
        // section 1.3 floor — the previous fixture seeded zero
        // redeemed referrals, so the floor could never pass
        // and the pin could not catch a floor-gated leak.
        // Seed the trailing 8 complete weeks (window Mon
        // 2026-08-31 .. Sun 2026-10-25 local) exactly as
        // SlowDayAnalyzerTest's floor-pass fixture: 10 visits
        // per week on six weekdays, 1 per week on Tuesday.
        $business = World::business(['slow_day_weekday' => null]);
        $owner = World::owner($business);
        $windowStart = Carbon::parse('2026-08-31 12:00:00', 'America/New_York');
        for ($week = 0; $week < 8; $week++) {
            foreach ([0 => 10, 1 => 10, 2 => 1, 3 => 10, 4 => 10, 5 => 10, 6 => 10] as $weekday => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $offset = ($weekday + 6) % 7;
                    $this->analyzerVisit($business, $windowStart->copy()->addDays($week * 7 + $offset)->addHours($i));
                }
            }
        }
        $this->analyzerVisit($business, Carbon::parse('2026-06-01 12:00:00', 'America/New_York'));

        // The floor DOES pass, and names Tuesday — an
        // evaluation the pin can now leak-check against.
        $result = \App\Services\Campaigns\SlowDayAnalyzer::analyze($business);
        $this->assertTrue($result['passes']);
        $this->assertSame(2, $result['candidate_weekday']);

        // SD-04 rendered in full for this fixture's totals
        // (Tuesday 8; typical weekday 488 / 7, rounded 70).
        $sd04 = 'Tuesdays bring you the fewest referral visits: 8 in the last 8 weeks, 70 on a typical weekday. A ready-made text for today is ready to review.';
        $sd09 = 'Your quietest day for referral visits: Tuesday (8 visits in the last 8 weeks; a typical weekday had 70).';
        $sd10 = 'We do not have enough of your visit history to name a quiet day yet';

        // Composer / campaigns page.
        $response = $this->actingAs($owner)->get(route('campaigns.create'));
        $response->assertOk()
            ->assertDontSee($sd04)
            ->assertDontSee($sd09)
            ->assertDontSee('Your quietest day for referral visits')
            ->assertDontSee('fewest referral visits')
            ->assertDontSee('Your referral visits are fewest on')
            ->assertDontSee($sd10);
        // The campaigns index likewise carries no analyzer claim.
        $this->actingAs($owner)->get(route('campaigns.index'))
            ->assertOk()
            ->assertDontSee('fewest referral visits')
            ->assertDontSee('Your quietest day for referral visits')
            ->assertDontSee($sd10);
        // Action Center.
        $this->actingAs($owner)->get(route('actions.index'))
            ->assertOk()
            ->assertDontSee($sd04)
            ->assertDontSee('fewest referral visits')
            ->assertDontSee('Your quietest day for referral visits')
            ->assertDontSee($sd10);
        // But the analyzer DID run and record its PASSING
        // evaluation, dormant: exactly one log row, naming
        // the would-be slow day that no surface showed.
        $logs = \App\Models\SlowDayAnalyzerLog::query()->where('business_id', $business->id)->get();
        $this->assertCount(1, $logs);
        $this->assertSame(2, $logs->sole()->candidate_weekday);
    }

    // ---------- ID-03: the win-back prompt ----------

    public function test_winback_prompt_fires_at_five_sendable_with_the_honest_count(): void
    {
        $business = World::business();
        $this->succeededCard($business);
        for ($i = 0; $i < 5; $i++) {
            $this->lapsed($business);
        }

        $items = $this->items($business);
        $this->assertArrayHasKey('lapsed_referrer_winback', $items);
        $this->assertSame(
            '5 past referrers have gone quiet. A ready-made win-back text is ready to review.',
            $items['lapsed_referrer_winback']['line'],
        );
        $this->assertSame('Start the text', $items['lapsed_referrer_winback']['action_label']);
        $this->assertStringNotContainsString('stopped sharing', $items['lapsed_referrer_winback']['line']);
        $this->assertStringNotContainsString('last visit', $items['lapsed_referrer_winback']['line']);
    }

    public function test_winback_prompt_absent_below_five_and_when_past_due(): void
    {
        $business = World::business();
        $this->succeededCard($business);
        for ($i = 0; $i < 4; $i++) {
            $this->lapsed($business);
        }
        $this->assertArrayNotHasKey('lapsed_referrer_winback', $this->items($business));

        $this->lapsed($business);
        $this->assertArrayHasKey('lapsed_referrer_winback', $this->items($business));

        $business->forceFill(['billing_status' => 'past_due'])->save();
        // Condition 4 (two-key): mirror the ID-01 pin's
        // genuineness — the flow IS active in grace, so the
        // absence isolates the billing-active leg (P2), not
        // the flow gate. FLOW-STATE TRUTH train, as corrected
        // by the two-key Finding 1 fix (2026-10-30): the
        // billing.past_due audit row is the episode evidence
        // that makes this past_due datable and therefore in
        // grace; the failed record alone no longer dates it.
        $record = \App\Models\BillingRecord::create([
            'business_id' => $business->id,
            'kind' => \App\Models\BillingRecord::KIND_PER_REFERRAL_BATCH,
            'state' => \App\Models\BillingRecord::STATE_FAILED,
            'amount_cents' => 1800,
            'currency' => 'USD',
            'idempotency_key' => 'idea-prompts-ingrace-winback',
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
        ]);
        \App\Models\AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_ingrace_winback'], $business->fresh(), $record);
        $this->assertTrue($business->fresh()->flowIsActive());
        $this->assertArrayNotHasKey('lapsed_referrer_winback', $this->items($business->fresh()));
    }

    public function test_winback_prompt_stands_down_for_30_days_after_a_winback_send(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        for ($i = 0; $i < 6; $i++) {
            $this->lapsed($business);
        }
        SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Win back', 'status' => SmsCampaign::STATUS_SENT,
            'created_by' => $owner->id, 'audience_segment' => 'lapsed_referrers', 'sent_at' => now()->subDays(10),
        ]);
        $this->assertArrayNotHasKey('lapsed_referrer_winback', $this->items($business));
    }

    public function test_winback_dismissal_silences_eight_weeks_and_is_isolated_from_slow_day(): void
    {
        $business = World::business(['slow_day_weekday' => 3]);
        $this->succeededCard($business);
        $owner = World::owner($business);
        for ($i = 0; $i < 5; $i++) {
            $this->lapsed($business);
        }
        $center = app(ActionCenter::class);
        $items = $this->items($business);
        $this->assertArrayHasKey('lapsed_referrer_winback', $items);
        $this->assertArrayHasKey('slow_day_boost', $items);

        $this->assertTrue($center->dismiss($business, $owner, 'lapsed_referrer_winback'));

        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks(4));
        $items = $this->items($business->fresh());
        $this->assertArrayNotHasKey('lapsed_referrer_winback', $items);
        // Isolation: the slow-day prompt is a different policy;
        // its own (undismissed) row is governed by its own
        // gates — today is a Wednesday again at +4 weeks... the
        // picked day is Wednesday, so at a Wednesday it fires
        // (audience: the 5 lapsed members + F1 spacing from no
        // sends = sendable whole list >= 5).
        $this->assertArrayHasKey('slow_day_boost', $items);
    }

    // ---------- Condition 4 (two-key): win-back suppression completion ----------

    public function test_winback_dismissal_reappears_at_week_nine(): void
    {
        $business = World::business();
        $this->succeededCard($business);
        $owner = World::owner($business);
        for ($i = 0; $i < 5; $i++) {
            $this->lapsed($business);
        }
        $center = app(ActionCenter::class);
        $this->assertArrayHasKey('lapsed_referrer_winback', $this->items($business));
        $this->assertTrue($center->dismiss($business, $owner, 'lapsed_referrer_winback'));

        // Silent for the next 8 candidate weeks; back at +9.
        for ($week = 1; $week <= 8; $week++) {
            Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks($week));
            $this->assertArrayNotHasKey('lapsed_referrer_winback', $this->items($business->fresh()), "week {$week} should be silent");
        }
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks(9));
        $this->assertArrayHasKey('lapsed_referrer_winback', $this->items($business->fresh()));
    }

    public function test_winback_four_consecutive_ignored_weeks_silence_eight_weeks(): void
    {
        $business = World::business();
        $this->succeededCard($business);
        for ($i = 0; $i < 5; $i++) {
            $this->lapsed($business);
        }

        // Four consecutive shown-but-ignored weeks.
        for ($week = 0; $week < 4; $week++) {
            Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks($week));
            $this->assertArrayHasKey('lapsed_referrer_winback', $this->items($business->fresh()), "week {$week} should show");
        }

        // Silent for 8 weeks from the last shown week (+3):
        // still silent at +4 and at +11, back at +12.
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks(4));
        $this->assertArrayNotHasKey('lapsed_referrer_winback', $this->items($business->fresh()));
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks(11));
        $this->assertArrayNotHasKey('lapsed_referrer_winback', $this->items($business->fresh()));
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks(12));
        $this->assertArrayHasKey('lapsed_referrer_winback', $this->items($business->fresh()));
    }

    public function test_winback_send_resets_the_suppression_counters(): void
    {
        $business = World::business();
        $this->succeededCard($business);
        $owner = World::owner($business);
        for ($i = 0; $i < 5; $i++) {
            $this->lapsed($business);
        }
        $center = app(ActionCenter::class);
        $this->assertArrayHasKey('lapsed_referrer_winback', $this->items($business));
        $center->dismiss($business, $owner, 'lapsed_referrer_winback');

        // A sent win-back resets the counters (the sender's
        // noteSent path, routed by the campaign's segment).
        $sent = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Win back body',
            'status' => SmsCampaign::STATUS_SENT, 'created_by' => $owner->id,
            'audience_segment' => 'lapsed_referrers', 'sent_at' => now(),
        ]);
        CampaignPrompts::noteSent($sent);

        // At +5 weeks the P4 post-send silence (30 days) has
        // cleared but the 8-week dismissal silence has NOT —
        // the prompt can only be back because the send reset it.
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addWeeks(5));
        $this->assertArrayHasKey('lapsed_referrer_winback', $this->items($business->fresh()));
    }

    public function test_winback_prompt_events_are_logged_once_per_week(): void
    {
        $business = World::business();
        $this->succeededCard($business);
        for ($i = 0; $i < 5; $i++) {
            $this->lapsed($business);
        }
        $this->items($business);
        $this->items($business);
        $this->assertSame(1, CampaignPromptEvent::query()
            ->where('business_id', $business->id)
            ->where('item_key', 'lapsed_referrer_winback')
            ->where('event', 'shown')->count());
    }

    public function test_winback_prompt_stands_down_when_the_owner_just_acted_on_a_slow_day_prompt(): void
    {
        $business = World::business();
        $this->succeededCard($business);
        for ($i = 0; $i < 5; $i++) {
            $this->lapsed($business);
        }
        $this->assertArrayHasKey('lapsed_referrer_winback', $this->items($business));

        // P6: an acted slow-day prompt inside the last 7 days
        // stands the win-back prompt down.
        CampaignPrompts::recordActed($business, CampaignPrompts::ITEM_SLOW_DAY);
        $this->assertArrayNotHasKey('lapsed_referrer_winback', $this->items($business->fresh()));
    }

    public function test_winback_results_clause_renders_with_a_closed_window(): void
    {
        $business = World::business();
        $this->succeededCard($business);
        $owner = World::owner($business);
        for ($i = 0; $i < 5; $i++) {
            $this->lapsed($business);
        }
        $campaign = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Win back body', 'status' => SmsCampaign::STATUS_SENT,
            'created_by' => $owner->id, 'audience_segment' => 'lapsed_referrers',
            'sent_at' => now()->subDays(45), // outside the 30-day P4 silence
        ]);
        // Move "now" so the campaign is 13, then 15 days old
        // while P4's 30-day silence has passed in both cases:
        // simpler: age the campaign relative to a moved clock.
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00', 'America/New_York')->addDays(0));
        $campaign->forceFill(['sent_at' => now()->subDays(13)->subDays(30)])->save();
        // At 43 days old the 14-day window is long closed; use
        // the boundary instead: set sent_at to 13 days before a
        // clock 31+ days after the send for P4... P4 blocks any
        // prompt within 30 days of a win-back send, so a prompt
        // carrying WB-11 can only exist >= 31 days after it;
        // the clause's own gate is >= 14 full days. Assert the
        // clause IS present at 43 days.
        $line = $this->items($business->fresh())['lapsed_referrer_winback']['line'] ?? '';
        $this->assertStringContainsString('Last win-back: 0 texts delivered, and 0 past referrers sent a new referral within 14 days of the text.', $line);
    }
}
