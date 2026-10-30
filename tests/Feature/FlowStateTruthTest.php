<?php

namespace Tests\Feature;

use App\Models\BillingRecord;
use App\Models\PaymentMethod;
use App\Models\Reward;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsMessage;
use App\Support\BillingCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\World;
use Tests\TestCase;

/**
 * FLOW-STATE TRUTH train (2026-10-30), from the flow-runner
 * report of 2026-10-29 (A-D1, A-D2, B-F1..B-F6, D-P2-1): one
 * source of truth for flow-state rendering. Every surface
 * below must derive its claim from the SAME predicates the
 * gates use (Business::flowIsActive and its legs
 * hasFriendRewardText / billingAllowsFlow, Reward's
 * readyToClaim bucket), never a parallel derivation, a static
 * string, or a fallback that assumes the best. Pins run in
 * both directions: the disagreeing state is constructed and
 * the surface must agree with the gate.
 */
class FlowStateTruthTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 7000;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    private function failedRecord($business, string $key): BillingRecord
    {
        return BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'state' => BillingRecord::STATE_FAILED,
            'amount_cents' => 1800,
            'currency' => 'USD',
            'idempotency_key' => $key,
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
        ]);
    }

    private function card($business): PaymentMethod
    {
        return PaymentMethod::create([
            'business_id' => $business->id,
            'square_card_id' => 'card_fst_'.$business->id,
            'card_brand' => 'VISA',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_fst_'.$business->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Defect 1 (headliner): the dateless fallback dies.
    // ---------------------------------------------------------------

    // RE-PINNED by the two-key re-review fix (Finding 1,
    // 2026-10-30): this pin originally asserted the episode
    // dates from the failed record. That fallback is unbound
    // (see the Finding 1 section below), so a past_due episode
    // with NO audit row now FAILS CLOSED even though a failed
    // record exists: no date is derived from it, the gate is
    // paused at once, and every surface states the paused
    // truth — the same outcome the no-evidence census pin
    // below asserts, because a failed record is not episode
    // evidence.
    public function test_past_due_without_audit_row_dates_from_the_failed_record(): void
    {
        Carbon::setTestNow('2026-03-10 02:00:00');
        $business = World::business(['billing_status' => 'past_due']);
        $this->failedRecord($business, 'fst-norecord-audit');
        $owner = World::owner($business->fresh());

        Carbon::setTestNow('2026-03-12 12:00:00');

        $this->assertNull($business->fresh()->dunningGraceEndsAt());
        $this->assertTrue($business->fresh()->dunningPausedAt());
        $this->assertFalse($business->fresh()->flowIsActive());
        $this->assertSame(
            'Your QR code and customer links are paused until you update your card.',
            BillingCopy::statusLine($business->fresh())
        );

        foreach ([route('settings.billing'), route('dashboard'), route('actions.index')] as $url) {
            $page = $this->actingAs($owner)->get($url);
            $page->assertOk();
            $page->assertSee('Your QR code and customer links are paused until you update your card.');
            $page->assertDontSee('keeps working');
            $page->assertDontSee('keep working during the grace period');
        }
    }

    // RE-PINNED with the same fix: no record-derived schedule
    // exists to pause "on" — the undated episode is paused
    // from the first read, and stays paused.
    public function test_past_due_without_audit_row_pauses_on_schedule(): void
    {
        Carbon::setTestNow('2026-03-10 02:00:00');
        $business = World::business(['billing_status' => 'past_due']);
        $this->failedRecord($business, 'fst-norecord-pause');
        $owner = World::owner($business->fresh());

        Carbon::setTestNow('2026-03-20 12:00:00');

        $this->assertTrue($business->fresh()->dunningPausedAt());
        $this->assertFalse($business->fresh()->flowIsActive());
        $this->assertSame(
            'Your QR code and customer links are paused until you update your card.',
            BillingCopy::statusLine($business->fresh())
        );

        foreach ([route('settings.billing'), route('dashboard'), route('actions.index')] as $url) {
            $page = $this->actingAs($owner)->get($url);
            $page->assertOk();
            $page->assertSee('Your QR code and customer links are paused until you update your card.');
            $page->assertDontSee('keeps working');
            $page->assertDontSee('keep working during the grace period');
        }
    }

    // Fallback census pin: a past_due business with NO audit
    // row AND NO failed record (no episode evidence at all)
    // renders no dateless grace claim anywhere and is paused —
    // the pause follows the state, not the paperwork.
    public function test_past_due_with_no_episode_evidence_fails_closed(): void
    {
        $business = World::business(['billing_status' => 'past_due']);
        $owner = World::owner($business);

        $this->assertNull($business->fresh()->dunningGraceEndsAt());
        $this->assertTrue($business->fresh()->dunningPausedAt());
        $this->assertFalse($business->fresh()->flowIsActive());

        foreach ([route('settings.billing'), route('dashboard'), route('actions.index')] as $url) {
            $page = $this->actingAs($owner)->get($url);
            $page->assertOk();
            $page->assertSee('Your QR code and customer links are paused until you update your card.');
            $page->assertDontSee('keep working during the grace period');
            $page->assertDontSee('keeps working');
        }
    }

    // The audit-row path is unchanged — and is now the ONLY
    // path: a dated episode derives its grace end from the
    // audit row when one exists, even if a NEWER failed record
    // exists (the audit row is the episode of record; a record
    // alone is never an anchor — see Finding 1 below).
    public function test_audit_row_still_wins_over_the_failed_record(): void
    {
        Carbon::setTestNow('2026-03-01 12:00:00');
        $business = World::business(['billing_status' => 'past_due']);
        $this->failedRecord($business, 'fst-audit-wins');

        Carbon::setTestNow('2026-03-10 12:00:00');
        \App\Models\AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_x'], $business->fresh(), null);
        Carbon::setTestNow(null);

        $graceEnd = $business->fresh()->dunningGraceEndsAt();
        $this->assertNotNull($graceEnd);
        $this->assertSame('2026-03-17 12:00:00', $graceEnd->format('Y-m-d H:i:s'));
    }

    // ---------------------------------------------------------------
    // Defect 2: onboarding finish names the real remaining gates.
    // ---------------------------------------------------------------

    public function test_finish_with_both_gates_open_names_both(): void
    {
        $business = World::business([
            'billing_status' => 'none',
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
        ]);
        $owner = World::owner($business);
        $this->assertFalse($business->fresh()->flowIsActive());

        $response = $this->actingAs($owner)->get(route('onboarding.finish'));
        $response->assertOk();
        $response->assertSee("You're set up, two steps from live.", false);
        $response->assertSee('Two things are still missing: your rewards and a card on file. Set your reward and add a card, and your code turns on; visits are free until you pass 5.');
        $response->assertDontSee('Everything is ready except your rewards. Set your reward and your code turns on right away');
        $response->assertDontSee('Everything is ready except a card on file');
    }

    public function test_finish_with_only_the_reward_gate_open_keeps_the_reward_branch(): void
    {
        $business = World::business([
            'billing_status' => 'active',
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
        ]);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('onboarding.finish'));
        $response->assertOk();
        $response->assertSee('Everything is ready except your rewards. Set your reward and your code turns on right away; visits are free until you pass 5.');
        $response->assertDontSee('Two things are still missing');
    }

    // ---------------------------------------------------------------
    // Defect 3: the card step's success panels follow the gate.
    // ---------------------------------------------------------------

    public function test_card_step_done_panel_names_the_open_reward_gate(): void
    {
        $business = World::business([
            'billing_status' => 'active',
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
        ]);
        $this->card($business);
        $owner = World::owner($business->fresh());
        $this->assertFalse($business->fresh()->flowIsActive());

        $response = $this->actingAs($owner)->get(route('onboarding.step', 'card'));
        $response->assertOk();
        $response->assertSee('✓ Card on file.', false);
        $response->assertSee('Your code turns on once you set your friend reward.');
        $response->assertDontSee('Your code is on.');
    }

    public function test_card_step_saved_panel_names_the_open_reward_gate(): void
    {
        $business = World::business([
            'billing_status' => 'active',
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
        ]);
        $this->card($business);
        $owner = World::owner($business->fresh());

        $response = $this->actingAs($owner)
            ->withSession(['onboarding_card_saved' => true])
            ->get(route('onboarding.step', 'card'));
        $response->assertOk();
        $response->assertSee('Your code turns on once you set your friend reward.');
        $response->assertDontSee('Your code is on.');
    }

    public function test_card_step_panels_claim_code_on_only_when_the_gate_is_on(): void
    {
        $business = World::business(['billing_status' => 'active']);
        $this->card($business);
        $owner = World::owner($business->fresh());
        $this->assertTrue($business->fresh()->flowIsActive());

        $this->actingAs($owner)->get(route('onboarding.step', 'card'))
            ->assertOk()
            ->assertSee('Your code is on.');

        $this->actingAs($owner)
            ->withSession(['onboarding_card_saved' => true])
            ->get(route('onboarding.step', 'card'))
            ->assertOk()
            ->assertSee('✓ Your code is on. Nothing is charged today. Your first 5 visits are free.', false);
    }

    // ---------------------------------------------------------------
    // Defect 4: the billing no-card panel derives from the gate.
    // ---------------------------------------------------------------

    public function test_billing_panel_on_a_live_cardless_business_says_visits_keep_counting_not_turn_on(): void
    {
        // RE-DERIVED at the activation merge (2026-10-30): this
        // pin asserted the flow-state panel's "restart monthly
        // charges" heading; the activation redesign re-keyed
        // the panel (its design pass owned the wording — the
        // flow-state train's own comment deferred it), and a
        // live cardless business now reads the counting truth.
        // The card-removed state: billing active, reward set,
        // free allowance untouched, no card — and the code
        // live (the gate says so).
        $business = World::business(['billing_status' => 'active', 'free_visits_remaining' => 5]);
        $owner = World::owner($business);
        $this->assertTrue($business->fresh()->flowIsActive());

        $response = $this->actingAs($owner)->get(route('settings.billing'));
        $response->assertOk();
        $response->assertSee('No card on file. Visits keep counting while your free visits last');
        $response->assertDontSee('Add a card to turn on your QR code');

        // The dashboard checklist row makes the same claim in
        // the same state: no "required to activate" there.
        $dashboard = $this->actingAs($owner)->get(route('dashboard'));
        $dashboard->assertOk();
        $dashboard->assertDontSee('Add a card (required to activate your QR code)');
    }

    public function test_billing_panel_before_activation_names_the_reward_leg_not_the_card(): void
    {
        // RE-DERIVED at the activation merge (2026-10-30): the
        // turn-on framing survives only attached to the REWARD
        // leg (activation redesign, flowBlockReason order);
        // the card-based turn-on heading is retired with the
        // card gate.
        $business = World::business([
            'billing_status' => 'none',
            'free_visits_remaining' => 5,
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
        ]);
        $owner = World::owner($business);
        $this->assertFalse($business->fresh()->flowIsActive());

        $response = $this->actingAs($owner)->get(route('settings.billing'));
        $response->assertOk();
        $response->assertSee('Your code is not on yet');
        $response->assertSee('Your code turns on once your friend reward is also set.');
        $response->assertDontSee('Add a card to turn on your QR code');
    }

    // ---------------------------------------------------------------
    // Defect 5: one ready count, one derivation.
    // ---------------------------------------------------------------

    public function test_dashboard_tile_counts_only_what_the_rewards_page_calls_ready(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550101', 'Reg', 'Ular'));
        [$redeemed] = World::referral($membership, World::customer('+12125550102', 'Fri', 'End'), [
            'state' => \App\Models\Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        [$heldReward] = World::reward($membership, $redeemed, ['hold' => true, 'hold_rules' => ['velocity_daily']]);

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();
        $this->assertSame(0, $response->viewData('tiles')['rewards_waiting']);

        $rewardsPage = $this->actingAs($owner)->get(route('rewards.index'));
        $rewardsPage->assertOk();
        $this->assertSame(0, $rewardsPage->viewData('counts')['available']);
        $this->assertSame(1, $rewardsPage->viewData('counts')['held']);

        // A second, unheld available reward counts on both.
        [$redeemed2] = World::referral($membership, World::customer('+12125550103', 'Sec', 'Ond'), [
            'state' => \App\Models\Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        World::reward($membership, $redeemed2);

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $this->assertSame(1, $response->viewData('tiles')['rewards_waiting']);
        $rewardsPage = $this->actingAs($owner)->get(route('rewards.index'));
        $this->assertSame(1, $rewardsPage->viewData('counts')['available']);
        $this->assertSame(1, $rewardsPage->viewData('counts')['held']);
        $this->assertNotNull($heldReward);
    }

    // ---------------------------------------------------------------
    // Lesser (a): accrued text charges render on Billing.
    // ---------------------------------------------------------------

    public function test_billing_month_block_prints_the_accrued_text_amount(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        $business = World::business(['billing_status' => 'active', 'free_visits_remaining' => 5]);
        $this->card($business);

        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'recipient_count' => 24,
            'segment_count' => 1,
            'metered_cents' => 48,
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => World::owner($business)->id,
            'sent_at' => now(),
        ]);
        $customer = World::customer('+1212555'.($this->seq++), 'Cam', 'Paign');
        [$membership] = World::membership($business, $customer);
        $messageId = SmsMessage::create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164,
            'purpose' => 'campaign',
            'messaging_service' => 'marketing',
            'template_key' => 'campaign',
            'rendered_body' => 'Spring sale is on',
            'twilio_message_sid' => 'SM_FST_1',
            'delivery_state' => 'sent',
            'consent_basis' => 'customer_checkbox',
            'opt_out_state_at_send' => false,
        ])->id;
        SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $membership->id,
            'customer_id' => $customer->id,
            'phone_e164' => $customer->phone_e164,
            'twilio_message_sid' => 'SM_FST_1',
            'sms_message_id' => $messageId,
            'delivery_state' => 'sent',
            'segments' => 24,
            'amount_cents' => 48,
        ]);

        $owner = World::owner($business->fresh());
        $response = $this->actingAs($owner)->get(route('settings.billing'));
        $response->assertOk();
        $response->assertSee('Text charges so far this month: $0.48');
        $response->assertSee('Your amount so far is under $1, so it carries forward. It joins your next monthly charge.');
    }

    // ---------------------------------------------------------------
    // Lesser (b): a held line names its reason or does not render.
    // ---------------------------------------------------------------

    public function test_ruleless_hold_renders_the_generic_reason_everywhere(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550111', 'Reg', 'Ular'));
        [$redeemed] = World::referral($membership, World::customer('+12125550112', 'Fri', 'End'), [
            'state' => \App\Models\Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        World::reward($membership, $redeemed, ['hold' => true, 'hold_rules' => null]);

        $generic = 'Flagged for review by the automatic risk checks.';

        $rewards = $this->actingAs($owner)->get(route('rewards.index'));
        $rewards->assertOk();
        $rewards->assertSee('Held for review: '.$generic);
        $this->assertStringNotContainsString('Held for review: <', $rewards->getContent());
        $this->assertStringNotContainsString('Held for review:  ', $rewards->getContent());

        $queue = $this->actingAs($owner)->get(route('reviews'));
        $queue->assertOk();
        $queue->assertSee($generic);
    }

    public function test_hold_reasons_for_hold_falls_back_only_when_empty(): void
    {
        $this->assertSame(
            ['Flagged for review by the automatic risk checks.'],
            \App\Support\HoldReasons::sentencesForHold([])
        );
        $this->assertSame(
            ['Flagged for review by the automatic risk checks.'],
            \App\Support\HoldReasons::sentencesForHold(null)
        );
        $business = World::business();
        $this->assertSame(
            ['This referrer reached the daily limit of 5 rewarded referrals.'],
            \App\Support\HoldReasons::sentencesForHold(['velocity_daily'], $business)
        );
        // The pure per-rule map is unchanged.
        $this->assertSame([], \App\Support\HoldReasons::sentences(null));
    }

    // ---------------------------------------------------------------
    // Two-key re-review (2026-10-30), Finding 1: the failed-record
    // fallback is NOT episode-bound, so it is removed. The ONLY
    // episode anchor in the schema is the billing.past_due audit
    // row (written by SquareWebhookHandler in the same transaction
    // as the status flip, targeted at the exact failed record).
    // Failed BillingRecords also come from MonthlyCharger and
    // PendingRecordSweeper, which never set past_due and never
    // write that audit row, and the record carries no failed-at
    // field (created_at is the pending creation, updated_at is the
    // last save). So an audit-missing episode FAILS CLOSED, and
    // DunningService reads the same single derivation.
    // ---------------------------------------------------------------

    private const PAUSED_TRUTH = 'Update your card and complete a successful charge to turn it back on.';

    private function openEpisode($business, string $at, $target = null): void
    {
        Carbon::setTestNow(Carbon::parse($at));
        \App\Models\AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_episode'], $business->fresh() ?? $business, $target);
        Carbon::setTestNow(null);
    }

    private function failedRecordAt($business, string $key, string $at, string $state = BillingRecord::STATE_FAILED): BillingRecord
    {
        Carbon::setTestNow(Carbon::parse($at));
        $record = $this->failedRecord($business, $key);
        if ($state !== BillingRecord::STATE_FAILED) {
            $record->forceFill(['state' => $state])->save();
        }
        Carbon::setTestNow(null);

        return $record;
    }

    // BOTH directions in one pin: a stale failed record from
    // BEFORE the (undated) episode and a newer failed record
    // from AFTER it. Neither may date the episode: the stale
    // pick would pause the business on day one, the newer pick
    // would fabricate a future grace end and keep the code on
    // past the real end (the SA-01 outcome on a new anchor).
    // With no audit row the episode fails closed instead.
    public function test_unbound_failed_records_neither_date_nor_delay_an_undated_episode(): void
    {
        $business = World::business(['billing_status' => 'past_due']);
        $this->failedRecordAt($business, 'fst-stale-record', '2026-02-01 12:00:00');
        $this->failedRecordAt($business, 'fst-newer-record', '2026-03-25 12:00:00');

        Carbon::setTestNow('2026-03-12 12:00:00');

        $this->assertNull($business->fresh()->dunningGraceEndsAt());
        $this->assertTrue($business->fresh()->dunningPausedAt());
        $this->assertFalse($business->fresh()->flowIsActive());
        $this->assertSame(
            'Your QR code and customer links are paused until you update your card.',
            BillingCopy::statusLine($business->fresh())
        );

        $owner = World::owner($business->fresh());
        foreach ([route('settings.billing'), route('dashboard'), route('actions.index')] as $url) {
            $page = $this->actingAs($owner)->get($url);
            $page->assertOk();
            $page->assertSee('Your QR code and customer links are paused until you update your card.');
            $page->assertDontSee('keeps working until');
        }
    }

    // The created-to-failed gap: a record is created PENDING
    // before the Square call and only later saved as failed,
    // so created_at and the failure time genuinely differ and
    // neither is an episode anchor. (The old pin created the
    // record directly in STATE_FAILED, so the two were always
    // equal and the gap was never exercised.)
    public function test_failed_record_created_long_before_it_failed_still_does_not_date_the_episode(): void
    {
        $business = World::business(['billing_status' => 'past_due']);

        Carbon::setTestNow('2026-03-01 12:00:00');
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_PER_REFERRAL_BATCH,
            'state' => BillingRecord::STATE_PENDING,
            'amount_cents' => 1800,
            'currency' => 'USD',
            'idempotency_key' => 'fst-created-then-failed',
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
        ]);
        Carbon::setTestNow('2026-03-10 12:00:00');
        $record->forceFill(['state' => BillingRecord::STATE_FAILED, 'error_code' => 'payment_failed'])->save();
        Carbon::setTestNow(null);

        $record = $record->fresh();
        $this->assertSame('2026-03-01 12:00:00', $record->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-10 12:00:00', $record->updated_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-03-12 12:00:00');
        $this->assertNull($business->fresh()->dunningGraceEndsAt());
        $this->assertTrue($business->fresh()->dunningPausedAt());
        $this->assertFalse($business->fresh()->flowIsActive());
    }

    // The dated episode is untouched by neighbouring failures:
    // with the audit row present, a stale record before it and
    // a newer record after it move the deadline in NEITHER
    // direction — the grace end stays the audit row's + 7 days.
    public function test_audit_episode_deadline_ignores_stale_and_newer_failed_records(): void
    {
        $business = World::business(['billing_status' => 'past_due']);
        $this->failedRecordAt($business, 'fst-episode-stale', '2026-02-01 12:00:00');
        $episodeRecord = $this->failedRecordAt($business, 'fst-episode-record', '2026-03-10 12:00:00');
        $this->openEpisode($business, '2026-03-10 12:00:00', $episodeRecord);
        $this->failedRecordAt($business, 'fst-episode-newer', '2026-03-25 12:00:00');

        $graceEnd = $business->fresh()->dunningGraceEndsAt();
        $this->assertNotNull($graceEnd);
        $this->assertSame('2026-03-17 12:00:00', $graceEnd->format('Y-m-d H:i:s'));
        $this->assertFalse($business->fresh()->dunningPausedAt(Carbon::parse('2026-03-16 12:00:00')));
        $this->assertTrue($business->fresh()->dunningPausedAt(Carbon::parse('2026-03-18 12:00:00')));
    }

    // One derivation, both halves of the policy: for an
    // audit-missing episode DunningService sends NOTHING (it
    // cannot date the episode) and the in-app surfaces state
    // the paused truth — never a dated grace the email half
    // treats as undatable. For a dated episode the day-0 mail
    // carries exactly Business::dunningGraceEndsAt().
    public function test_dunning_service_and_surfaces_share_the_one_derivation(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $undated = World::business(['billing_status' => 'past_due']);
        World::owner($undated);
        $this->failedRecordAt($undated, 'fst-dunning-undated', '2026-03-10 12:00:00');

        Carbon::setTestNow('2026-03-12 12:00:00');
        $summary = app(\App\Services\Billing\DunningService::class)->run();
        $this->assertGreaterThanOrEqual(1, $summary['skipped']);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        $this->assertNull($undated->fresh()->dunningGraceEndsAt());
        $this->assertSame(
            'Your QR code and customer links are paused until you update your card.',
            BillingCopy::statusLine($undated->fresh())
        );

        $dated = World::business(['billing_status' => 'past_due']);
        World::owner($dated);
        $record = $this->failedRecordAt($dated, 'fst-dunning-dated', '2026-03-10 12:00:00');
        $this->openEpisode($dated, '2026-03-10 12:00:00', $record);

        Carbon::setTestNow('2026-03-12 12:00:00');
        app(\App\Services\Billing\DunningService::class)->run();
        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\DunningPaymentFailedMail::class,
            fn ($mail) => $mail->graceEnd->format('Y-m-d H:i:s')
                === $dated->fresh()->dunningGraceEndsAt()->format('Y-m-d H:i:s')
        );
    }

    // ---------------------------------------------------------------
    // Two-key re-review (2026-10-30), Finding 2: the paused state
    // is NOT the no-card state. billingAllowsFlow() lumped them;
    // CardOnFileService::applySavedCard() only flips
    // none/setup_incomplete to active, so "add a card and your
    // code turns on" is false for a past_due business past its
    // grace end — it unpauses only via a successful charge
    // (SquareWebhookHandler). Each surface below gets the paused
    // truth, with a past_due paused business constructed on it.
    // ---------------------------------------------------------------

    private function pastDuePausedBusiness(array $overrides = [])
    {
        $business = World::business(array_merge(['billing_status' => 'past_due'], $overrides));
        $record = $this->failedRecordAt($business, 'fst-paused-'.$business->id, '2026-03-10 12:00:00');
        $this->openEpisode($business, '2026-03-10 12:00:00', $record);
        Carbon::setTestNow('2026-03-20 12:00:00');
        $this->assertTrue($business->fresh()->dunningPausedAt());
        $this->assertFalse($business->fresh()->flowIsActive());

        return $business->fresh();
    }

    public function test_finish_screen_gives_a_past_due_paused_business_the_paused_truth(): void
    {
        $business = $this->pastDuePausedBusiness();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('onboarding.finish'));
        $response->assertOk();
        $response->assertSee(self::PAUSED_TRUTH);
        $response->assertDontSee('Everything is ready except a card on file');
        $response->assertDontSee('Add one and your code turns on right away');
        $response->assertDontSee('Two things are still missing');
        $response->assertDontSee("You're live", false);
    }

    public function test_finish_screen_paused_business_with_rewards_missing_names_both_truths(): void
    {
        $business = $this->pastDuePausedBusiness([
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
        ]);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('onboarding.finish'));
        $response->assertOk();
        $response->assertSee(self::PAUSED_TRUTH);
        $response->assertSee('You also still need to set your reward.');
        $response->assertDontSee('Two things are still missing: your rewards and a card on file. Set your reward and add a card, and your code turns on');
    }

    public function test_qr_notice_gives_a_past_due_paused_business_the_paused_truth(): void
    {
        $business = $this->pastDuePausedBusiness();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('owner.qr'));
        $response->assertOk();
        $response->assertSee(self::PAUSED_TRUTH);
        $response->assertDontSee('Add a card to turn it on');
    }

    public function test_qr_notice_paused_business_with_reward_cleared_still_gets_the_paused_truth(): void
    {
        $business = $this->pastDuePausedBusiness([
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
        ]);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('owner.qr'));
        $response->assertOk();
        $response->assertSee(self::PAUSED_TRUTH);
        $response->assertDontSee('Set your friend reward and add a card to turn it on');
        $response->assertDontSee('Set your friend reward to turn it on');
    }

    // The reviewer's concrete shape: a TEXT-ONLY charge failure
    // (texts are never free) puts the business past_due with all
    // 5 free visits intact; after grace lapses the owner removes
    // the failed card, and the no-card panel must NOT say "add a
    // card to turn on your QR code".
    public function test_billing_no_card_panel_gives_a_past_due_paused_business_the_paused_truth(): void
    {
        $business = World::business([
            'billing_status' => 'past_due',
            'free_visits_remaining' => 5,
        ]);
        Carbon::setTestNow('2026-03-10 12:00:00');
        $record = BillingRecord::create([
            'business_id' => $business->id,
            'kind' => BillingRecord::KIND_CAMPAIGN_METERING,
            'state' => BillingRecord::STATE_FAILED,
            'amount_cents' => 48,
            'currency' => 'USD',
            'idempotency_key' => 'fst-paused-text-only',
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end' => now()->subMonth()->endOfMonth(),
        ]);
        \App\Models\AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_text'], $business, $record);
        Carbon::setTestNow('2026-03-20 12:00:00');

        $this->assertTrue($business->fresh()->dunningPausedAt());
        $this->assertSame(5, $business->fresh()->free_visits_remaining);
        // No card on file: the failed card was removed.
        $this->assertSame(0, \App\Models\PaymentMethod::query()->where('business_id', $business->id)->count());

        $owner = World::owner($business->fresh());
        $response = $this->actingAs($owner)->get(route('settings.billing'));
        $response->assertOk();
        $response->assertSee(self::PAUSED_TRUTH);
        $response->assertDontSee('Add a card to turn on your QR code');
        $response->assertDontSee('Add a card to restart monthly charges');
    }

    // ---------------------------------------------------------------
    // Truth sweep 2026-10-30, T-1: the billing card-present well
    // (F2-BILL-02) has no billing-state guard. In the literal
    // state past_due-PAUSED + failed card still on file + friend
    // reward text cleared, setting the reward does NOT turn the
    // code on (the dunning leg precedes the reward leg in
    // flowBlockReason()), so the well must render the paused
    // truth + remedy + the still-open reward leg, and the
    // F2-BILL-02 turns-on string must be ABSENT. The existing
    // textless-not-paused pin in MissingRewardTextGateTest keeps
    // the current string in its valid state.
    // ---------------------------------------------------------------

    public function test_billing_card_present_well_gives_a_past_due_paused_reward_cleared_business_the_paused_truth(): void
    {
        $business = $this->pastDuePausedBusiness([
            'friend_reward_text' => null,
            'referrer_reward_text' => null,
        ]);
        $this->card($business);
        $business = $business->fresh();
        $this->assertTrue($business->billingIsPastDuePaused());
        $this->assertFalse($business->flowIsActive());
        $this->assertSame('dunning_paused', $business->flowBlockReason());

        $owner = World::owner($business);
        $response = $this->actingAs($owner)->get(route('settings.billing'));
        $response->assertOk();
        $response->assertSee(self::PAUSED_TRUTH);
        $response->assertSee('You also still need to set your reward.');
        $response->assertSee('Set your rewards');
        $response->assertDontSee('Your card is saved. Your code turns on once you set your friend reward.');
        $response->assertDontSee('turns on once you set your friend reward');
    }

    // ---------------------------------------------------------------
    // Truth sweep 2026-10-30, item 2f: the three paused branches
    // carry "A payment failed and the grace period has ended."
    // In the audit-row-ABSENT fail-closed state no grace period
    // was ever dated, so that history clause is invented there.
    // The clause branches on dunningGraceEndsAt() === null
    // (neutral form); the dated case keeps the current wording.
    // ---------------------------------------------------------------

    public function test_paused_branches_drop_the_grace_history_clause_when_the_episode_cannot_be_dated(): void
    {
        // No billing.past_due audit row is written for this
        // business: the episode cannot be dated at all.
        $business = World::business(['billing_status' => 'past_due']);
        $this->assertNull($business->fresh()->dunningGraceEndsAt());
        $this->assertTrue($business->fresh()->billingIsPastDuePaused());
        $owner = World::owner($business->fresh());

        // Billing no-card panel (no card on file in this fixture).
        $billing = $this->actingAs($owner)->get(route('settings.billing'));
        $billing->assertOk();
        $billing->assertSee('Your QR code is paused');
        $billing->assertSee(self::PAUSED_TRUTH);
        $billing->assertDontSee('grace period has ended');

        // QR-inactive notice, first (paused) arm.
        $qr = $this->actingAs($owner)->get(route('owner.qr'));
        $qr->assertOk();
        $qr->assertSee('This code is paused.');
        $qr->assertSee(self::PAUSED_TRUTH);
        $qr->assertDontSee('grace period has ended');

        // Onboarding finish, paused arm: the neutral clause
        // composes to the sweep's neutral sentence exactly.
        $finish = $this->actingAs($owner)->get(route('onboarding.finish'));
        $finish->assertOk();
        $finish->assertSee("You're set up, but your code is paused.", false);
        $finish->assertSee('A payment failed, so your code is paused.');
        $finish->assertSee(self::PAUSED_TRUTH);
        $finish->assertDontSee('grace period has ended');

        // The shared clause itself, in the undatable state.
        $this->assertSame(
            'A payment failed',
            BillingCopy::pausedCauseClause($business->fresh())
        );
    }

    public function test_paused_branches_keep_the_grace_history_clause_when_the_episode_is_dated(): void
    {
        $business = $this->pastDuePausedBusiness();
        $this->assertNotNull($business->dunningGraceEndsAt());
        $owner = World::owner($business);

        $billing = $this->actingAs($owner)->get(route('settings.billing'));
        $billing->assertOk();
        $billing->assertSee('Your QR code is paused');
        $billing->assertSee('A payment failed and the grace period has ended.');
        $billing->assertSee(self::PAUSED_TRUTH);

        $qr = $this->actingAs($owner)->get(route('owner.qr'));
        $qr->assertOk();
        $qr->assertSee('This code is paused.');
        $qr->assertSee('A payment failed and the grace period has ended.');
        $qr->assertSee(self::PAUSED_TRUTH);

        $finish = $this->actingAs($owner)->get(route('onboarding.finish'));
        $finish->assertOk();
        $finish->assertSee('A payment failed and the grace period has ended, so your code is paused.');
        $finish->assertSee(self::PAUSED_TRUTH);

        $this->assertSame(
            'A payment failed and the grace period has ended',
            BillingCopy::pausedCauseClause($business->fresh())
        );
    }

    // ---------------------------------------------------------------
    // Truth sweep 2026-10-30, item 3: Reward::readyToClaim()
    // counts must agree with claim truth past closure expiry.
    // Closure-expiry transitions must be applied at set level
    // on the dashboard and Rewards reads, beside vesting.
    // ---------------------------------------------------------------

    private function closedBusinessWithAvailableReward(int $closedDaysAgo): array
    {
        $business = World::business([
            'status' => 'closed',
            'closed_at' => now()->subDays($closedDaysAgo),
        ]);
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550131', 'Reg', 'Ular'));
        [$redeemed] = World::referral($membership, World::customer('+12125550132', 'Fri', 'End'), [
            'state' => \App\Models\Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDay(),
        ]);
        [$reward] = World::reward($membership, $redeemed, ['hold' => false]);

        return [$business, $owner, $reward];
    }

    public function test_dashboard_tile_and_rewards_page_expire_an_available_reward_after_the_closure_window(): void
    {
        [$business, $owner, $reward] = $this->closedBusinessWithAvailableReward(31);
        $this->assertTrue($business->fresh()->closureWindowEnded());
        $this->assertSame(Reward::STATE_AVAILABLE, $reward->fresh()->state);

        $dashboard = $this->actingAs($owner)->get(route('dashboard'));
        $dashboard->assertOk();
        $this->assertSame(0, $dashboard->viewData('tiles')['rewards_waiting']);

        $rewardsPage = $this->actingAs($owner)->get(route('rewards.index', ['filter' => 'available']));
        $rewardsPage->assertOk();
        $this->assertSame(0, $rewardsPage->viewData('counts')['available']);
        $this->assertSame(0, $rewardsPage->viewData('rewards')->total());

        $this->assertSame(Reward::STATE_EXPIRED, $reward->fresh()->state);
    }

    public function test_dashboard_tile_and_rewards_page_still_count_an_available_reward_inside_the_closure_window(): void
    {
        [$business, $owner, $reward] = $this->closedBusinessWithAvailableReward(29);
        $this->assertFalse($business->fresh()->closureWindowEnded());

        $dashboard = $this->actingAs($owner)->get(route('dashboard'));
        $dashboard->assertOk();
        $this->assertSame(1, $dashboard->viewData('tiles')['rewards_waiting']);

        $rewardsPage = $this->actingAs($owner)->get(route('rewards.index', ['filter' => 'available']));
        $rewardsPage->assertOk();
        $this->assertSame(1, $rewardsPage->viewData('counts')['available']);
        $this->assertSame(1, $rewardsPage->viewData('rewards')->total());

        $this->assertSame(Reward::STATE_AVAILABLE, $reward->fresh()->state);
    }

    // ---------------------------------------------------------------
    // Lesser (c): dates render in the business's timezone.
    // ---------------------------------------------------------------

    public function test_top_referrers_registered_date_is_business_local(): void
    {
        Carbon::setTestNow('2026-10-30 12:00:00');
        $business = World::business(['timezone' => 'America/New_York']);
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550121', 'Top', 'Referrer'));
        // 2026-10-30 02:55 UTC is Oct 29, 22:55 in New York.
        $membership->forceFill(['created_at' => Carbon::parse('2026-10-30 02:55:00')])->save();
        World::referral($membership, World::customer('+12125550122', 'Fri', 'End'), [
            'state' => \App\Models\Referral::STATE_REDEEMED, 'redeemed_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('Registered Oct 29, 2026');
        $response->assertDontSee('Registered Oct 30, 2026');
    }
}
