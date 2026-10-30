<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BillingEvent;
use App\Models\PaymentMethod;
use App\Models\Referral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\World;
use Tests\TestCase;

/**
 * Train A (night loop 2026-10-28): SA-01 (past-due banner date and
 * paused truth), SA-03 (visit deadline visible; held referrals do
 * not expire), SA-07 (Settings index card state), SA-12 / F-1
 * (friend page names the referrer's reward). Copy: Amendment 35.
 */
class TrainANightTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // SA-01 helpers
    // ---------------------------------------------------------------

    private function openPastDueEpisode($business, string $at): void
    {
        Carbon::setTestNow(Carbon::parse($at));
        AuditLog::write('billing.past_due', null, ['square_payment_id' => 'sq_pay_failed'], $business, null);
        Carbon::setTestNow(null);
    }

    private function pastDueOwner(string $episodeAt)
    {
        $business = World::business(['billing_status' => 'past_due']);
        $this->openPastDueEpisode($business, $episodeAt);

        return World::owner($business->fresh());
    }

    // SA-01: in grace, the real grace-end date (business-local)
    // renders on the Billing page, the Overview gate, and the
    // Action Center. Episode 2026-03-10 02:00 UTC + 7 days ends
    // 2026-03-17 02:00 UTC, which is March 16 in New York: the
    // local date is the discriminating leg.
    public function test_sa01_grace_end_date_renders_on_all_three_surfaces(): void
    {
        $owner = $this->pastDueOwner('2026-03-10 02:00:00');
        Carbon::setTestNow('2026-03-12 12:00:00');

        $line = 'Your QR code keeps working until March 16, 2026. Update your card by then to keep it on.';
        $old = 'Your QR code and customer links keep working during the grace period.';

        foreach ([route('settings.billing'), route('dashboard'), route('actions.index')] as $url) {
            $page = $this->actingAs($owner)->get($url);
            $page->assertOk();
            $page->assertSee($line);
            $page->assertSee('A payment failed. Please update your card to keep billing current.');
            $page->assertSee('Update card');
            $page->assertDontSee($old);
            $page->assertDontSee('past_due');
        }
    }

    // SA-01: after the grace ends and the flow has paused, the
    // banner states the paused truth; the keeps-working line is
    // ABSENT on all three surfaces (both-direction pin).
    public function test_sa01_paused_wording_renders_after_the_grace_ends(): void
    {
        $owner = $this->pastDueOwner('2026-03-10 02:00:00');
        Carbon::setTestNow('2026-03-20 12:00:00');

        $paused = 'Your QR code and customer links are paused until you update your card.';

        foreach ([route('settings.billing'), route('dashboard'), route('actions.index')] as $url) {
            $page = $this->actingAs($owner)->get($url);
            $page->assertOk();
            $page->assertSee($paused);
            $page->assertSee('Update card');
            $page->assertDontSee('keeps working');
            $page->assertDontSee('keep working until');
        }
    }

    // SA-01, FLOW-STATE TRUTH train (2026-10-30) superseding
    // the original undated pin: a past_due episode with NO
    // audit row and NO failed record cannot be dated from any
    // billing state, so it fails closed — the paused truth
    // renders and the dateless grace line is ABSENT (the
    // retired fallback branch is what kept SA-01 alive).
    // Episodes missing only the audit row date from the failed
    // record instead; see FlowStateTruthTest.
    public function test_sa01_undatable_episode_renders_the_paused_truth(): void
    {
        $business = World::business(['billing_status' => 'past_due']);
        $owner = World::owner($business);

        $this->actingAs($owner)->get(route('settings.billing'))
            ->assertOk()
            ->assertSee('Your QR code and customer links are paused until you update your card.')
            ->assertDontSee('keep working during the grace period');
    }

    // SA-01 walk catch (lifecycle + state-truth pass, 2026-10-28):
    // a past_due business that is also closed (or disabled) has
    // its flow off for a reason a card update cannot fix, so
    // neither the keeps-working line nor the paused line may
    // render: both would be false in that state.
    public function test_sa01_closed_past_due_business_reads_off_not_keeps_working(): void
    {
        $business = World::business(['billing_status' => 'past_due', 'status' => 'closed', 'closed_at' => now()->subDay()]);
        $this->openPastDueEpisode($business, '2026-03-10 02:00:00');
        Carbon::setTestNow('2026-03-12 12:00:00');
        $owner = World::owner($business->fresh());

        foreach ([route('settings.billing'), route('dashboard')] as $url) {
            $page = $this->actingAs($owner)->get($url);
            $page->assertOk();
            $page->assertSee('Your QR code and customer links are off.');
            $page->assertDontSee('keeps working');
            $page->assertDontSee('are paused until you update your card');
        }
    }

    // ---------------------------------------------------------------
    // SA-03 display
    // ---------------------------------------------------------------

    private function referralFor($business, array $overrides = [], string $friendPhone = '+12125550777')
    {
        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer($friendPhone, 'Fri', 'End');
        [$referral] = World::referral($membership, $friend, $overrides);

        return $referral;
    }

    public function test_sa03_pending_referral_detail_and_list_show_good_until_in_local_time(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        // 2026-11-27 04:41 UTC is November 26 in New York.
        $referral = $this->referralFor($business, ['expires_at' => Carbon::parse('2026-11-27 04:41:00')]);

        $this->actingAs($owner)->get(route('referrals.show', $referral))
            ->assertOk()
            ->assertSee('Good until November 26, 2026');

        $this->actingAs($owner)->get(route('referrals.index'))
            ->assertOk()
            ->assertSee('Good until November 26, 2026');
    }

    public function test_sa03_review_queue_shows_the_visit_window_end_beside_the_actions(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->referralFor($business, [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now(),
            'expires_at' => Carbon::parse('2026-11-27 04:41:00'),
        ]);

        $this->actingAs($owner)->get(route('reviews'))
            ->assertOk()
            ->assertSee('Visit window ends November 26, 2026')
            ->assertSee('Release')
            ->assertSee('Deny');
    }

    // SA-03 walk catch: a held referral whose stored date has
    // already passed (possible only because held referrals no
    // longer expire) must not be described in the future tense.
    // The queue and the detail state the ended truth; the queue
    // adds the true release consequence.
    public function test_sa03_held_referral_whose_window_already_ended_reads_in_the_past_tense(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referral = $this->referralFor($business, [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now()->subDays(2),
            'expires_at' => Carbon::parse('2026-10-20 12:00:00'),
        ]);

        $queue = $this->actingAs($owner)->get(route('reviews'))->assertOk();
        $queue->assertSee('Visit window ended October 20, 2026 while this waited. Releasing gives the friend a fresh window.');
        $queue->assertDontSee('Visit window ends');

        $detail = $this->actingAs($owner)->get(route('referrals.show', $referral))->assertOk();
        $detail->assertSee('Visit window ended October 20, 2026 while held for review.');
        $detail->assertDontSee('Good until');

        $list = $this->actingAs($owner)->get(route('referrals.index'))->assertOk();
        $list->assertSee('Visit window ended October 20, 2026 while held for review.');
        $list->assertDontSee('Good until');
    }

    // ---------------------------------------------------------------
    // SA-03 guard: a held referral does not expire
    // ---------------------------------------------------------------

    public function test_sa03_held_referral_past_its_date_stays_pending_through_every_expiry_path(): void
    {
        $business = World::business();
        $referral = $this->referralFor($business, [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($referral->fresh()->applyExpiry());
        $this->assertSame(Referral::STATE_PENDING, $referral->fresh()->state);

        Referral::applyExpiryForBusiness($business->fresh());
        $this->assertSame(Referral::STATE_PENDING, $referral->fresh()->state);

        $this->artisan('referrals:expire')->assertSuccessful();
        $this->assertSame(Referral::STATE_PENDING, $referral->fresh()->state);

        // No billing event is created or changed by any of this.
        $this->assertSame(0, BillingEvent::count());
    }

    public function test_sa03_unheld_referral_expiry_is_unchanged(): void
    {
        $business = World::business();
        $referral = $this->referralFor($business, ['expires_at' => now()->subHour()]);

        $this->assertTrue($referral->fresh()->applyExpiry());
        $this->assertSame(Referral::STATE_EXPIRED, $referral->fresh()->state);
        $this->assertSame(0, BillingEvent::count());
    }

    public function test_sa03_released_referral_gets_at_least_the_fresh_minimum_window(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $referral = $this->referralFor($business, [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now()->subDays(3),
            'expires_at' => now()->subDay(),
        ]);

        $before = now();
        $this->actingAs($owner)->post(route('reviews.referrals.release', ['referral' => $referral->id]))
            ->assertRedirect();

        $fresh = $referral->fresh();
        $this->assertFalse($fresh->hold);
        // Fresh minimum: the lesser of 7 days and the business's
        // 30-day window = 7 days from the release.
        $this->assertTrue($fresh->expires_at->greaterThanOrEqualTo($before->copy()->addDays(7)->subMinutes(5)), 'expires_at '.$fresh->expires_at.' is earlier than the fresh minimum window');
        $this->assertTrue($fresh->expires_at->lessThanOrEqualTo(now()->addDays(7)->addMinutes(5)), 'expires_at '.$fresh->expires_at.' overshoots the fresh minimum window');
        $this->assertSame(0, BillingEvent::count());
    }

    public function test_sa03_released_referral_with_a_long_window_keeps_its_date(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $date = now()->addDays(20)->startOfMinute();
        $referral = $this->referralFor($business, [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now(),
            'expires_at' => $date,
        ]);

        $this->actingAs($owner)->post(route('reviews.referrals.release', ['referral' => $referral->id]))
            ->assertRedirect();

        $this->assertSame($date->format('Y-m-d H:i'), $referral->fresh()->expires_at->format('Y-m-d H:i'));
    }

    public function test_sa03_fresh_minimum_is_the_lesser_of_seven_days_and_the_business_window(): void
    {
        $business = World::business(['redemption_window_days' => 3]);
        $owner = World::owner($business);
        $referral = $this->referralFor($business, [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);

        $before = now();
        $this->actingAs($owner)->post(route('reviews.referrals.release', ['referral' => $referral->id]))
            ->assertRedirect();

        $fresh = $referral->fresh();
        $this->assertTrue($fresh->expires_at->greaterThanOrEqualTo($before->copy()->addDays(3)->subMinutes(5)));
        $this->assertTrue($fresh->expires_at->lessThanOrEqualTo(now()->addDays(3)->addMinutes(5)));
    }

    // Abuse pass (2026-10-28): SA-01 — a timezone change is a
    // display change only. The pause instant is a timestamp
    // (episode + grace days); no owner-editable field moves it.
    public function test_abuse_sa01_changing_the_business_timezone_cannot_move_the_pause(): void
    {
        $business = World::business(['billing_status' => 'past_due']);
        $this->openPastDueEpisode($business, '2026-03-10 12:00:00');

        Carbon::setTestNow('2026-03-17 11:30:00');
        $this->assertTrue($business->fresh()->flowIsActive());

        $business->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();
        $this->assertTrue($business->fresh()->flowIsActive());

        Carbon::setTestNow('2026-03-17 12:30:00');
        $this->assertFalse($business->fresh()->flowIsActive());

        $business->forceFill(['timezone' => 'Pacific/Auckland'])->save();
        $this->assertFalse($business->fresh()->flowIsActive());
    }

    // Abuse pass: SA-03 — release is the owner's remedy for a
    // HOLD, not a window-extension button. Releasing a referral
    // that was never held must not move its date, and a second
    // release after a real one must not extend it again.
    public function test_abuse_sa03_release_spam_cannot_extend_a_window(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $unheld = $this->referralFor($business, ['expires_at' => now()->addDay()->startOfMinute()], '+12125550778');
        $this->actingAs($owner)->post(route('reviews.referrals.release', ['referral' => $unheld->id]))->assertRedirect();
        $this->assertSame(
            $unheld->expires_at->format('Y-m-d H:i'),
            $unheld->fresh()->expires_at->format('Y-m-d H:i'),
            'Releasing an unheld referral extended its window.'
        );

        $held = $this->referralFor($business, [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now()->subDay(),
            'expires_at' => now()->subDay(),
        ], '+12125550779');
        $this->actingAs($owner)->post(route('reviews.referrals.release', ['referral' => $held->id]))->assertRedirect();
        $afterFirst = $held->fresh()->expires_at->copy();
        $this->assertTrue($afterFirst->greaterThan(now()->addDays(6)), 'The genuine release did not restore a fresh window.');

        Carbon::setTestNow(now()->addDay());
        $this->actingAs($owner)->post(route('reviews.referrals.release', ['referral' => $held->id]))->assertRedirect();
        $this->assertSame(
            $afterFirst->format('Y-m-d H:i:s'),
            $held->fresh()->expires_at->format('Y-m-d H:i:s'),
            'A second release extended the window again.'
        );
        Carbon::setTestNow(null);
    }

    // Abuse pass: SA-03 — staff action cannot complete a held
    // referral, and the attempt moves no money.
    public function test_abuse_sa03_staff_cannot_redeem_a_gate1_normalized_referral_with_a_spent_window(): void
    {
        // Option B rewrite (2026-10-28): the original pin (a held
        // referral blocks staff redemption) is the behavior the
        // owner ruling abolishes. The SA-03 abuse property that
        // survives is narrower: a legacy held referral whose
        // window SPENT while it sat held must not become
        // redeemable again. That is enforced by the conversion's
        // Gate 1 normalization (design pass §6.3: hold cleared,
        // risk data retained) plus the normal expiry clock —
        // the normalized row expires on first read and staff get
        // no nonce and no redemption. This fixture is therefore
        // the row AS GATE 1 LEAVES IT (hold cleared), with its
        // spent window intact.
        $business = World::business();
        $referral = $this->referralFor($business, [
            'hold' => false, 'hold_rules' => null, 'held_at' => null,
            'risk_score' => 0, 'risk_rules' => ['velocity_daily'],
            'expires_at' => now()->subDay(),
        ]);
        $token = $referral->redeem_token_encrypted;

        $page = $this->get(route('redeem.show', ['token' => $token]));
        $page->assertOk();
        if (preg_match('/name="nonce" value="([^"]+)"/', $page->getContent(), $m)) {
            $this->post(route('redeem.confirm', ['token' => $token]), ['nonce' => $m[1]]);
        }

        $this->assertSame(Referral::STATE_EXPIRED, $referral->fresh()->state);
        $this->assertSame(0, BillingEvent::count());
    }

    // Abuse pass: SA-07 — the card's expiry fields are written
    // only from the Square capture. Extra fields smuggled into
    // a settings payload change nothing.
    public function test_abuse_sa07_card_expiry_cannot_be_edited_through_a_settings_payload(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $card = $this->cardFor($business, ['exp_month' => 1, 'exp_year' => 2020]);

        $this->actingAs($owner)->post(route('settings.profile.update'), [
            'name' => $business->name,
            'contact_email' => $business->contact_email,
            'contact_phone' => '(212) 555-0401',
            'street' => $business->street,
            'city' => $business->city,
            'state' => $business->state,
            'postal_code' => $business->postal_code,
            'country' => 'US',
            'exp_month' => 12,
            'exp_year' => 2099,
        ])->assertRedirect();

        $this->assertSame(2020, $card->fresh()->exp_year);
        $html = $this->actingAs($owner)->get(route('settings'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/pill pill-error[^>]*>\s*Card expired/', $html);
    }

    // ---------------------------------------------------------------
    // SA-07: Settings index card state
    // ---------------------------------------------------------------

    private function cardFor($business, array $overrides = []): PaymentMethod
    {
        return PaymentMethod::create(array_merge([
            'business_id' => $business->id,
            'square_card_id' => 'card_train_a',
            'card_brand' => 'VISA',
            'last4' => '1111',
            'exp_month' => 12,
            'exp_year' => (int) now()->addYears(2)->format('Y'),
            'is_default' => true,
            'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'train-a-'.$business->id.'-'.uniqid(),
        ], $overrides));
    }

    public function test_sa07_expired_card_on_settings_index_shows_the_expired_state(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->cardFor($business, ['exp_month' => 1, 'exp_year' => 2020]);

        $html = $this->actingAs($owner)->get(route('settings'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/pill pill-error[^>]*>\s*Card expired/', $html);
        $this->assertStringNotContainsString('Card on file', $html);
    }

    public function test_sa07_card_expiring_this_month_on_settings_index_shows_the_warning(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->cardFor($business, ['exp_month' => (int) now()->format('n'), 'exp_year' => (int) now()->format('Y')]);

        $html = $this->actingAs($owner)->get(route('settings'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/pill pill-warning[^>]*>\s*Card expires soon/', $html);
        $this->assertStringNotContainsString('Card on file', $html);
    }

    public function test_sa07_valid_card_on_settings_index_is_unchanged(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->cardFor($business);

        $html = $this->actingAs($owner)->get(route('settings'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/pill pill-success[^>]*>\s*Card on file/', $html);
        $this->assertStringNotContainsString('Card expired', $html);
        $this->assertStringNotContainsString('Card expires soon', $html);
    }

    public function test_sa07_past_due_state_on_settings_index_is_unchanged(): void
    {
        $business = World::business(['billing_status' => 'past_due']);
        $owner = World::owner($business);
        $this->cardFor($business, ['exp_month' => 1, 'exp_year' => 2020]);

        $html = $this->actingAs($owner)->get(route('settings'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/pill pill-error[^>]*>\s*Past due/', $html);
        $this->assertStringNotContainsString('Card expired', $html);
    }

    // ---------------------------------------------------------------
    // SA-12 / F-1: the friend page names the referrer's reward
    // ---------------------------------------------------------------

    public function test_sa12_friend_page_shows_the_referrer_reward_line_for_two_businesses(): void
    {
        $cases = [
            ['friend' => '$5 off your first order', 'referrer' => '$5 off', 'name' => 'Sam', 'expected' => 'Sam gets $5 off when you visit.'],
            ['friend' => 'A free coffee', 'referrer' => 'A free drink', 'name' => 'Nina', 'expected' => 'Nina gets A free drink when you visit.'],
        ];

        foreach ($cases as $i => $case) {
            $business = World::business([
                'friend_reward_text' => $case['friend'],
                'referrer_reward_text' => $case['referrer'],
            ]);
            $referrer = World::customer('+121255506'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $case['name'], 'Reed');
            [$membership] = World::membership($business, $referrer);

            $page = $this->get(route('refer.friend', ['token' => $membership->plainShareToken()]));
            $page->assertOk();
            $page->assertSee($case['expected']);
            $page->assertSee('Claim '.$case['friend'].' at '.$business->name.'.');
        }
    }

    // SA-12 walk catch: a whitespace-only stored referrer
    // reward is not a reward; the line must not render an
    // empty promise ("Sam gets  when you visit.").
    public function test_sa12_friend_page_with_a_blank_referrer_reward_keeps_the_friend_only_form(): void
    {
        // OR-1 re-derivation (Amendment 49): the blank-referrer
        // population is the UNIFIED configuration (flag off) —
        // the referrer gets the same reward, so the page now
        // renders the ORS-03 both-line instead of the old
        // friend-only form. The name is kept for history; the
        // SA-12 absence (no "{name} gets ..." split line in the
        // visible body) still holds.
        $business = World::business([
            'friend_reward_text' => '$5 off your first order',
            'referrer_reward_text' => '   ',
            'reward_split' => false,
        ]);
        $referrer = World::customer('+12125550656', 'Sam', 'Reed');
        [$membership] = World::membership($business, $referrer);

        $page = $this->get(route('refer.friend', ['token' => $membership->plainShareToken()]));
        $page->assertOk();
        // Train B (RS-N1) re-scope, recorded for the two-key
        // reviewer: the absence is now asserted on the visible
        // body. The phrase "when you visit." also ends the
        // RS-N1 og:description ("Claim it here, then show it
        // when you visit."), a different, separately pinned
        // sentence that lives in the head only; the SA-12 line
        // itself ("{name} gets {reward} when you visit.") must
        // still never render visibly in this state.
        $body = substr($page->getContent(), strpos($page->getContent(), '</head>'));
        $this->assertStringContainsString('You both get $5 off your first order when you visit.', $body);
        $this->assertStringNotContainsString('Sam gets', $body);
    }

    public function test_sa12_friend_page_without_a_referrer_reward_keeps_the_friend_only_form(): void
    {
        // OR-1 re-derivation, as in the blank case above: no
        // referrer text + flag off is UNIFIED, and the unified
        // both-line renders.
        $business = World::business([
            'friend_reward_text' => '$5 off your first order',
            'referrer_reward_text' => null,
            'reward_split' => false,
        ]);
        $referrer = World::customer('+12125550655', 'Sam', 'Reed');
        [$membership] = World::membership($business, $referrer);

        $page = $this->get(route('refer.friend', ['token' => $membership->plainShareToken()]));
        $page->assertOk();
        $page->assertSee('Claim $5 off your first order at '.$business->name.'.');
        // Body-scoped, as in the blank-reward case above
        // (Train B RS-N1 og:description lives in the head).
        $body = substr($page->getContent(), strpos($page->getContent(), '</head>'));
        $this->assertStringContainsString('You both get $5 off your first order when you visit.', $body);
        $this->assertStringNotContainsString('Sam gets', $body);
    }
}
