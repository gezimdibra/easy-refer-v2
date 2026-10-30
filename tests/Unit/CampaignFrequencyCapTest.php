<?php

namespace Tests\Unit;

use App\Models\Business;
use App\Models\Membership;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsOptOut;
use App\Services\Campaigns\CampaignAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\World;
use Tests\TestCase;

/**
 * F1 (Amendment 45): the per-recipient campaign frequency cap
 * inside CampaignAudience. Hard cap per (business, phone): at
 * most 4 touches in any rolling 30 days AND at least 7 days
 * between touches. A touch = a recipient row handed to the
 * provider (queued/sent/delivered on a sent/sending campaign);
 * failed/skipped/not_sent never count. Precedence: opt-out ->
 * no-consent -> owner-asserted -> spacing -> volume.
 */
class CampaignFrequencyCapTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSequence = 8100;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-06-30 12:00:00');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function member(Business $business, array $consent = ['sms_consent' => true, 'consent_source' => 'customer_checkbox']): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill($consent)->save();

        return $membership->fresh();
    }

    /** Seed one prior campaign touch (or non-touch) for a member's phone. */
    private function touch(Business $business, Membership $membership, Carbon $at, string $state = 'delivered', string $campaignStatus = SmsCampaign::STATUS_SENT): void
    {
        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Earlier sale',
            'status' => $campaignStatus,
            'created_by' => World::owner($business)->id,
        ]);
        $row = SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $membership->id,
            'customer_id' => $membership->customer_id,
            'phone_e164' => $membership->customer->phone_e164,
            'delivery_state' => $state,
            'segments' => 1,
            'amount_cents' => 2,
        ]);
        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    public function test_spacing_limb_excludes_a_member_touched_inside_seven_days(): void
    {
        $business = World::business();
        $recent = $this->member($business);
        $this->touch($business, $recent, $this->now->copy()->subDays(3));
        $fresh = $this->member($business);

        $audience = CampaignAudience::forBusiness($business);

        $this->assertSame([$fresh->id], collect($audience['memberships'])->pluck('id')->all());
        $this->assertSame(1, $audience['exclusions']['recently_texted_spacing']);
        $this->assertSame(0, $audience['exclusions']['recently_texted_cap']);
    }

    public function test_spacing_boundary_exactly_seven_days_is_eligible(): void
    {
        $business = World::business();
        $member = $this->member($business);
        $this->touch($business, $member, $this->now->copy()->subDays(7));

        $audience = CampaignAudience::forBusiness($business);

        $this->assertSame([$member->id], collect($audience['memberships'])->pluck('id')->all());
        $this->assertSame(0, $audience['exclusions']['recently_texted_spacing']);
    }

    public function test_volume_limb_fourth_touch_is_allowed_the_fifth_is_blocked(): void
    {
        $business = World::business();
        $three = $this->member($business);
        foreach ([21, 14, 8] as $daysAgo) {
            $this->touch($business, $three, $this->now->copy()->subDays($daysAgo));
        }
        $four = $this->member($business);
        foreach ([28, 21, 14, 8] as $daysAgo) {
            $this->touch($business, $four, $this->now->copy()->subDays($daysAgo));
        }

        $audience = CampaignAudience::forBusiness($business);

        $this->assertSame([$three->id], collect($audience['memberships'])->pluck('id')->all());
        $this->assertSame(1, $audience['exclusions']['recently_texted_cap']);
        $this->assertSame(0, $audience['exclusions']['recently_texted_spacing']);
    }

    public function test_volume_boundary_a_touch_exactly_thirty_days_old_ages_out(): void
    {
        $business = World::business();
        $member = $this->member($business);
        foreach ([30, 21, 14, 8] as $daysAgo) {
            $this->touch($business, $member, $this->now->copy()->subDays($daysAgo));
        }

        $audience = CampaignAudience::forBusiness($business);

        $this->assertSame([$member->id], collect($audience['memberships'])->pluck('id')->all());
        $this->assertSame(0, $audience['exclusions']['recently_texted_cap']);
    }

    public function test_spacing_wins_precedence_over_volume_for_one_member(): void
    {
        $business = World::business();
        $member = $this->member($business);
        foreach ([21, 14, 8, 2] as $daysAgo) {
            $this->touch($business, $member, $this->now->copy()->subDays($daysAgo));
        }

        $audience = CampaignAudience::forBusiness($business);

        $this->assertSame(1, $audience['exclusions']['recently_texted_spacing']);
        $this->assertSame(0, $audience['exclusions']['recently_texted_cap']);
        $this->assertSame(1, $audience['exclusions']['recently_texted_spacing'] + $audience['exclusions']['recently_texted_cap']);
    }

    public function test_stopped_and_recent_member_counts_once_as_opted_out(): void
    {
        $business = World::business();
        $member = $this->member($business);
        $this->touch($business, $member, $this->now->copy()->subDay());
        SmsOptOut::create(['phone_e164' => $member->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $audience = CampaignAudience::forBusiness($business);

        $this->assertSame(1, $audience['exclusions']['opted_out']);
        $this->assertSame(0, $audience['exclusions']['recently_texted_spacing']);
        $this->assertSame(1, array_sum($audience['exclusions']));
    }

    public function test_failed_skipped_and_not_sent_rows_never_count_as_touches(): void
    {
        $business = World::business();
        $member = $this->member($business);
        $this->touch($business, $member, $this->now->copy()->subDay(), SmsCampaignRecipient::STATE_FAILED);
        $this->touch($business, $member, $this->now->copy()->subDay(), SmsCampaignRecipient::STATE_SKIPPED);
        $this->touch($business, $member, $this->now->copy()->subDay(), SmsCampaignRecipient::STATE_NOT_SENT);
        $this->touch($business, $member, $this->now->copy()->subDay(), SmsCampaignRecipient::STATE_UNDELIVERED);
        // A draft campaign's rows never count either.
        $this->touch($business, $member, $this->now->copy()->subDay(), 'delivered', SmsCampaign::STATUS_DRAFT);

        $audience = CampaignAudience::forBusiness($business);

        $this->assertSame([$member->id], collect($audience['memberships'])->pluck('id')->all());
        $this->assertSame(0, $audience['exclusions']['recently_texted_spacing']);
    }

    public function test_remove_and_readd_cannot_launder_phone_history(): void
    {
        $business = World::business();
        $member = $this->member($business);
        $phone = $member->customer->phone_e164;
        $this->touch($business, $member, $this->now->copy()->subDays(2));

        $member->forceFill(['removed_at' => now()])->save();
        // Re-added: the membership is restored (the pair is
        // unique, so re-adding revives this row) — and even a
        // fresh membership row would key on the same phone.
        $member->forceFill(['removed_at' => null, 'sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        $audience = CampaignAudience::forBusiness($business);

        $this->assertSame([], collect($audience['memberships'])->pluck('id')->all());
        $this->assertSame(1, $audience['exclusions']['recently_texted_spacing']);
    }

    public function test_cross_business_history_never_moves_another_businesss_counts(): void
    {
        $businessA = World::business();
        $businessB = World::business();
        $memberA = $this->member($businessA);
        // Same phone enrolled at business B.
        [$memberB] = World::membership($businessB, $memberA->customer);
        $memberB->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();
        foreach ([20, 13, 9, 8] as $daysAgo) {
            $this->touch($businessA, $memberA, $this->now->copy()->subDays($daysAgo));
        }

        $audienceB = CampaignAudience::forBusiness($businessB->fresh());

        $this->assertSame([$memberB->id], collect($audienceB['memberships'])->pluck('id')->all());
        $this->assertSame(0, $audienceB['exclusions']['recently_texted_spacing']);
        $this->assertSame(0, $audienceB['exclusions']['recently_texted_cap']);
    }

    public function test_earliest_return_is_the_first_skipped_members_reeligibility(): void
    {
        $business = World::business();
        $spacing = $this->member($business);
        $this->touch($business, $spacing, $this->now->copy()->subDays(3)); // back Jul 4
        $capped = $this->member($business);
        foreach ([28, 21, 14, 8] as $daysAgo) {
            $this->touch($business, $capped, $this->now->copy()->subDays($daysAgo));
        } // oldest ages out Jul 2

        $audience = CampaignAudience::forBusiness($business);

        $this->assertNotNull($audience['recency_earliest_return']);
        $this->assertSame('2026-07-02', $audience['recency_earliest_return']->format('Y-m-d'));
    }

    public function test_earliest_return_clears_both_limbs_for_a_member_bound_by_spacing_and_volume(): void
    {
        // Two-key Condition 3 (2026-10-28): four touches inside
        // one week trip BOTH limbs. Spacing alone would clear
        // Jul 6 (last touch Jun 29 + 7d); on that date all four
        // touches are still inside the 30-day window, so the
        // volume limb still skips the member. The true return
        // is the later clearance: the oldest touch (Jun 24)
        // aging out on Jul 24.
        $business = World::business();
        $member = $this->member($business);
        foreach ([6, 4, 2, 1] as $daysAgo) {
            $this->touch($business, $member, $this->now->copy()->subDays($daysAgo));
        }

        $audience = CampaignAudience::forBusiness($business);

        // The exclusion bucket keeps the spacing precedence.
        $this->assertSame(1, $audience['exclusions']['recently_texted_spacing']);
        $this->assertSame(0, $audience['exclusions']['recently_texted_cap']);
        $this->assertNotNull($audience['recency_earliest_return']);
        $this->assertSame('2026-07-24', $audience['recency_earliest_return']->format('Y-m-d'));

        // The builder's own predicate is the ground truth: on
        // the returned date the member is eligible again...
        $return = $audience['recency_earliest_return']->copy();
        Carbon::setTestNow($return);
        $onReturn = CampaignAudience::forBusiness($business);
        $this->assertSame([$member->id], collect($onReturn['memberships'])->pluck('id')->all());
        $this->assertSame(0, $onReturn['exclusions']['recently_texted_spacing'] + $onReturn['exclusions']['recently_texted_cap']);

        // ...and one day earlier they are not.
        Carbon::setTestNow($return->copy()->subDay());
        $dayBefore = CampaignAudience::forBusiness($business);
        $this->assertSame([], collect($dayBefore['memberships'])->pluck('id')->all());
        $this->assertSame(1, $dayBefore['exclusions']['recently_texted_spacing'] + $dayBefore['exclusions']['recently_texted_cap']);
    }

    public function test_earliest_return_for_five_touches_waits_for_the_second_oldest_to_age_out(): void
    {
        // Two-key Condition 3 (2026-10-28): five touches, the
        // last 8 days ago so spacing passes and the volume limb
        // alone binds. The oldest aging out (Jul 2) still
        // leaves four in the window; the member is eligible
        // only when the SECOND-oldest (Jun 9) ages out: Jul 9,
        // the (5 − 4 + 1)th-oldest touch + 30 days.
        $business = World::business();
        $member = $this->member($business);
        foreach ([28, 21, 14, 9, 8] as $daysAgo) {
            $this->touch($business, $member, $this->now->copy()->subDays($daysAgo));
        }

        $audience = CampaignAudience::forBusiness($business);

        $this->assertSame(0, $audience['exclusions']['recently_texted_spacing']);
        $this->assertSame(1, $audience['exclusions']['recently_texted_cap']);
        $this->assertNotNull($audience['recency_earliest_return']);
        $this->assertSame('2026-07-09', $audience['recency_earliest_return']->format('Y-m-d'));

        $return = $audience['recency_earliest_return']->copy();
        Carbon::setTestNow($return);
        $onReturn = CampaignAudience::forBusiness($business);
        $this->assertSame([$member->id], collect($onReturn['memberships'])->pluck('id')->all());
        $this->assertSame(0, $onReturn['exclusions']['recently_texted_spacing'] + $onReturn['exclusions']['recently_texted_cap']);

        Carbon::setTestNow($return->copy()->subDay());
        $dayBefore = CampaignAudience::forBusiness($business);
        $this->assertSame([], collect($dayBefore['memberships'])->pluck('id')->all());
        $this->assertSame(1, $dayBefore['exclusions']['recently_texted_spacing'] + $dayBefore['exclusions']['recently_texted_cap']);
    }

    public function test_a_three_segment_touch_counts_once(): void
    {
        $business = World::business();
        $member = $this->member($business);
        // Four touches, each 3 segments, spaced a week apart:
        // the volume limb counts touches, not segments, so the
        // member is at the cap exactly (blocked) — and three
        // such touches leave the member eligible.
        $other = $this->member($business);
        foreach ([21, 14, 8] as $daysAgo) {
            $campaign = SmsCampaign::create([
                'business_id' => $business->id, 'body' => 'Long one', 'status' => SmsCampaign::STATUS_SENT,
                'created_by' => World::owner($business)->id,
            ]);
            $row = SmsCampaignRecipient::create([
                'campaign_id' => $campaign->id, 'membership_id' => $other->id, 'customer_id' => $other->customer_id,
                'phone_e164' => $other->customer->phone_e164, 'delivery_state' => 'delivered', 'segments' => 3, 'amount_cents' => 6,
            ]);
            $at = $this->now->copy()->subDays($daysAgo);
            $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }

        $audience = CampaignAudience::forBusiness($business);
        $this->assertContains($other->id, collect($audience['memberships'])->pluck('id')->all());
    }
}
