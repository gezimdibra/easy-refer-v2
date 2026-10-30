<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Jobs\SendCampaignRecipient;
use App\Models\Business;
use App\Models\Membership;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * F1 feature pins (Amendment 45): the cap's owner surfaces —
 * composer rule line + breakdown clauses, confirm skip lines,
 * the F1-Z zero-sendable branch, the receipt stamp line, the
 * job-time re-check with its own-campaign exclusion, and the
 * §5.2 accounting identity (recency axis and daily-cap axis
 * named separately, every member accounted exactly once).
 */
class CampaignFrequencyCapTest extends TestCase
{
    use RefreshDatabase;

    private FakeMarketingSmsGateway $gateway;

    private int $phoneSequence = 9100;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeMarketingSmsGateway;
        $this->app->instance(MarketingSmsGateway::class, $this->gateway);
        $this->now = Carbon::parse('2026-06-30 12:00:00');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function member(Business $business): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        return $membership->fresh();
    }

    private function touch(Business $business, Membership $membership, int $daysAgo, string $state = 'delivered'): void
    {
        $campaign = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Earlier sale',
            'status' => SmsCampaign::STATUS_SENT, 'created_by' => World::owner($business)->id,
        ]);
        $row = SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'membership_id' => $membership->id,
            'customer_id' => $membership->customer_id, 'phone_e164' => $membership->customer->phone_e164,
            'delivery_state' => $state, 'segments' => 1, 'amount_cents' => 2,
        ]);
        $at = $this->now->copy()->subDays($daysAgo);
        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    private function capOut(Business $business, Membership $membership): void
    {
        foreach ([28, 21, 14, 8] as $daysAgo) {
            $this->touch($business, $membership, $daysAgo);
        }
    }

    private function draft(Business $business, string $body = 'June sale'): SmsCampaign
    {
        $campaign = SmsCampaign::create([
            'business_id' => $business->id, 'body' => $body,
            'status' => SmsCampaign::STATUS_DRAFT, 'created_by' => World::owner($business)->id,
        ]);
        app(CampaignSender::class)->stampFigures($campaign);

        return $campaign->fresh();
    }

    public function test_composer_and_confirm_name_the_recency_skips_and_the_rule(): void
    {
        $business = World::business();
        $spacing = $this->member($business);
        $this->touch($business, $spacing, 3);
        $capped = $this->member($business);
        $this->capOut($business, $capped);
        $this->member($business);
        $owner = World::owner($business);

        $create = $this->actingAs($owner)->get(route('campaigns.create'));
        $create->assertOk();
        $create->assertSee('We skip anyone who got a campaign text in the last 7 days, and anyone who has had 4 campaign texts in the last 30 days.', false);
        $create->assertSee('Sending to 1 customer who agreed to receive messages.', false);
        $create->assertSee('1 texted in the last 7 days', false);
        $create->assertSee('1 at the most texts for now', false);

        $campaign = $this->draft($business);
        $confirm = $this->actingAs($owner)->get(route('campaigns.confirm', $campaign));
        $confirm->assertOk();
        $confirm->assertSee('1 customer got a campaign text in the last 7 days and is skipped.', false);
        $confirm->assertSee('1 customer has had 4 campaign texts in the last 30 days, the most we send, and are skipped.', false);
        $confirm->assertSee('Send to 1 customer', false);
    }

    public function test_f1z_zero_sendable_suppresses_the_form_with_its_own_copy_and_date(): void
    {
        $business = World::business();
        $only = $this->member($business);
        $this->touch($business, $only, 3); // spacing until Jul 4 (business-local)
        $owner = World::owner($business);
        $campaign = $this->draft($business);

        $confirm = $this->actingAs($owner)->get(route('campaigns.confirm', $campaign));
        $confirm->assertOk();
        $confirm->assertSee('Total $0.00 for 0 customers. Every customer who agreed to texts got a campaign text too recently.', false);
        $confirm->assertSee('This draft stays saved. Some customers can get texts again starting Jul 4, 2026.', false);
        $confirm->assertDontSee('Send to', false);
        $confirm->assertDontSee("today's limit", false);
        $confirm->assertDontSee('No customers have confirmed yet', false);
        $confirm->assertDontSee('You can send it tomorrow.', false);

        // Composer twin: FQ-05 renders, CAMP-10 does not, and
        // the draft path (Review before sending) stays open.
        $create = $this->actingAs($owner)->get(route('campaigns.create', ['draft' => $campaign->id]));
        $create->assertOk();
        $create->assertSee('All of your customers who agreed to texts got a campaign text too recently. Some can get texts again starting Jul 4, 2026.', false);
        $create->assertSee('Review before sending', false);
        $create->assertDontSee('No customers have confirmed yet, so there is nobody to send to.', false);
    }

    public function test_two_axes_compose_on_confirm_with_the_accounting_identity(): void
    {
        // §5.2 in miniature: 5 consented; 1 spacing, 1 cap;
        // daily cap leaves room for 2 of the remaining 3.
        Config::set('campaigns.daily_cap', 2);
        $business = World::business();
        $spacing = $this->member($business);
        $this->touch($business, $spacing, 3);
        $capped = $this->member($business);
        $this->capOut($business, $capped);
        $this->member($business);
        $this->member($business);
        $this->member($business);
        $campaign = $this->draft($business);

        $confirm = $this->actingAs(World::owner($business))->get(route('campaigns.confirm', $campaign));
        $confirm->assertOk();
        $confirm->assertSee('1 customer got a campaign text in the last 7 days and is skipped.', false);
        $confirm->assertSee('1 customer has had 4 campaign texts in the last 30 days, the most we send, and are skipped.', false);
        $confirm->assertSee('2 of 3 today', false);
        $confirm->assertSee('for 2 customers today. The other 1 will not be sent. Once sent, this text cannot be unsent.', false);
        $confirm->assertSee('Send to 2 customers', false);
    }

    public function test_send_stamps_the_recency_skips_and_the_receipt_keeps_them_after_windows_roll(): void
    {
        Queue::fake();
        $business = World::business();
        $spacing = $this->member($business);
        $this->touch($business, $spacing, 3);
        $this->member($business);
        $campaign = $this->draft($business);

        $this->actingAs(World::owner($business))->post(route('campaigns.send', $campaign))->assertRedirect();
        $fresh = $campaign->fresh();
        $this->assertSame(1, $fresh->recent_spacing_skipped_count);
        $this->assertSame(0, $fresh->recent_cap_skipped_count);
        // No recipient row exists for the skipped member.
        $this->assertSame(1, $fresh->recipients()->count());

        // Finish the campaign, then age the touch that caused
        // the skip 40 days into the past: a live recompute
        // would now say 0 skipped, so a page still showing 1
        // is reading the frozen stamp, not recomputing.
        $fresh->forceFill(['status' => SmsCampaign::STATUS_SENT])->save();
        SmsCampaignRecipient::query()
            ->where('membership_id', $spacing->id)
            ->update(['created_at' => $this->now->copy()->subDays(40)]);
        // The aged member is eligible again in a live read
        // (the other member's spacing count below is its own
        // in-flight row from this very campaign, §3.1's
        // provisional touch — a different member's fact).
        $this->assertContains($spacing->id, collect(\App\Services\Campaigns\CampaignAudience::forBusiness($business)['memberships'])->pluck('id')->all());
        $page = $this->actingAs(World::owner($business))->get(route('campaigns.show', $fresh));
        $page->assertOk();
        $page->assertSee('Skipped (texted too recently): 1', false);
    }

    public function test_job_time_recheck_skips_a_recipient_touched_by_another_campaign(): void
    {
        $business = World::business();
        $member = $this->member($business);

        // Campaign B's row exists (created before campaign A's
        // touch landed — the two-drafts race).
        $campaignB = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Second blast',
            'status' => SmsCampaign::STATUS_SENDING, 'created_by' => World::owner($business)->id,
        ]);
        $rowB = SmsCampaignRecipient::create([
            'campaign_id' => $campaignB->id, 'membership_id' => $member->id,
            'customer_id' => $member->customer_id, 'phone_e164' => $member->customer->phone_e164,
            'delivery_state' => SmsCampaignRecipient::STATE_QUEUED, 'segments' => 1, 'amount_cents' => 2,
        ]);
        $this->touch($business, $member, 1);

        (new SendCampaignRecipient($rowB->id))->handle($this->gateway, app(CampaignSender::class));

        $rowB->refresh();
        $this->assertSame(SmsCampaignRecipient::STATE_SKIPPED, $rowB->delivery_state);
        $this->assertSame(SmsCampaignRecipient::SKIP_RECENTLY_TEXTED, $rowB->skip_reason);
        $this->assertNull($rowB->sms_message_id);
        $this->assertCount(0, $this->gateway->sent);
    }

    public function test_job_time_recheck_never_counts_a_campaigns_own_rows_against_itself(): void
    {
        // The catastrophic-misbuild pin: a campaign pacing out
        // over minutes must not skip its own later recipients
        // because its own earlier rows look "recent".
        $business = World::business();
        $members = [$this->member($business), $this->member($business), $this->member($business)];
        $campaign = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Big blast',
            'status' => SmsCampaign::STATUS_SENDING, 'created_by' => World::owner($business)->id,
        ]);
        $rows = [];
        foreach ($members as $member) {
            $rows[] = SmsCampaignRecipient::create([
                'campaign_id' => $campaign->id, 'membership_id' => $member->id,
                'customer_id' => $member->customer_id, 'phone_e164' => $member->customer->phone_e164,
                'delivery_state' => SmsCampaignRecipient::STATE_QUEUED, 'segments' => 1, 'amount_cents' => 2,
            ]);
        }
        // Same phone on two memberships is impossible per pair,
        // so the own-campaign hazard is same-phone rows: give
        // row 2 the same phone as row 1 (a household number on
        // a second membership is the real-world shape).
        $rows[1]->forceFill(['phone_e164' => $members[0]->customer->phone_e164])->save();

        foreach ($rows as $row) {
            (new SendCampaignRecipient($row->id))->handle($this->gateway, app(CampaignSender::class));
        }

        foreach ($rows as $row) {
            $this->assertSame(SmsCampaignRecipient::STATE_SENT, $row->fresh()->delivery_state);
        }
        $this->assertCount(3, $this->gateway->sent);
    }

    public function test_failed_recipients_stay_resendable_because_failures_never_count(): void
    {
        Queue::fake();
        $business = World::business();
        $member = $this->member($business);
        $this->touch($business, $member, 1, SmsCampaignRecipient::STATE_FAILED);

        $figures = app(CampaignSender::class)->figures($business, 'Retry sale');
        $this->assertSame(1, $figures['recipients']);
    }

    public function test_duplicate_campaign_inherits_the_cap(): void
    {
        Queue::fake();
        $business = World::business();
        $member = $this->member($business);
        $this->touch($business, $member, 2);
        $source = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Old sale',
            'status' => SmsCampaign::STATUS_SENT, 'created_by' => World::owner($business)->id,
        ]);

        $response = $this->actingAs(World::owner($business))->post(route('campaigns.duplicate', $source));
        $copy = SmsCampaign::query()->where('business_id', $business->id)->where('status', SmsCampaign::STATUS_DRAFT)->latest('id')->firstOrFail();
        $confirm = $this->actingAs(World::owner($business))->get(route('campaigns.confirm', $copy));
        $confirm->assertSee('Every customer who agreed to texts got a campaign text too recently.', false);
    }
}
