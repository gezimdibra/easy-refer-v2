<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Models\Membership;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * R26 Gate 3 follow-up (wording hardening, DRAFT for
 * coordinator ratification as addendum 29.1): the campaign
 * detail page's summary line for the stamped not_sent_count
 * hard-coded "Not sent (daily limit)", asserting one reason
 * for a figure the page itself does not prove: the stamped
 * column is written at send time, while the page's own rows
 * are derived separately and carry their own per-row reasons
 * (a not-sent row reads "Over the daily limit.", a skipped
 * row reads its opt-out / consent / removal reason), and the
 * Results block separately counts opted-out skips. On the
 * Gate 3 page the stamped 1 sat beside a table whose only
 * non-sent row was an opt-out skip. The summary therefore
 * carries the neutral "Not sent: {n}" and the per-row
 * reasons carry the specifics. No number, count, or
 * attribution changes: these pins hold every figure
 * byte-identical and the old assertive label absent.
 */
class CampaignNotSentLabelTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSequence = 9000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
    }

    private function member($business): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        return $membership->fresh();
    }

    public function test_sent_campaign_detail_uses_the_neutral_not_sent_label_and_keeps_every_count(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'status' => SmsCampaign::STATUS_SENT,
            'recipient_count' => 3,
            'segment_count' => 1,
            'metered_cents' => 2,
            'not_sent_count' => 2,
            'created_by' => $owner->id,
            'sent_at' => now(),
        ]);

        $delivered = $this->member($business);
        $message = SmsMessage::create([
            'business_id' => $business->id,
            'customer_id' => $delivered->customer_id,
            'phone_e164' => $delivered->customer->phone_e164,
            'purpose' => 'campaign',
            'messaging_service' => 'marketing',
            'template_key' => 'campaign',
            'rendered_body' => 'Spring sale is on',
            'twilio_message_sid' => 'SM_label_1',
            'delivery_state' => 'delivered',
        ]);
        SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $delivered->id,
            'customer_id' => $delivered->customer_id,
            'phone_e164' => $delivered->customer->phone_e164,
            'delivery_state' => SmsCampaignRecipient::STATE_DELIVERED,
            'segments' => 1,
            'amount_cents' => 2,
            'sms_message_id' => $message->id,
        ]);

        $optedOut = $this->member($business);
        SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $optedOut->id,
            'customer_id' => $optedOut->customer_id,
            'phone_e164' => $optedOut->customer->phone_e164,
            'delivery_state' => SmsCampaignRecipient::STATE_SKIPPED,
            'skip_reason' => SmsCampaignRecipient::SKIP_OPTED_OUT,
            'segments' => 1,
            'amount_cents' => 2,
        ]);

        $notSent = $this->member($business);
        SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $notSent->id,
            'customer_id' => $notSent->customer_id,
            'phone_e164' => $notSent->customer->phone_e164,
            'delivery_state' => SmsCampaignRecipient::STATE_NOT_SENT,
            'skip_reason' => SmsCampaignRecipient::SKIP_DAILY_CAP,
            'segments' => 1,
            'amount_cents' => 2,
        ]);

        $response = $this->actingAs($owner)->get(route('campaigns.show', $campaign));

        $response->assertOk()
            ->assertDontSee('Not sent (daily limit)')
            ->assertSee('Not sent: 2')
            // The per-row reasons still carry the specifics.
            ->assertSee('Over the daily limit.')
            ->assertSee('This number opted out before the send.')
            // Every figure is unchanged.
            ->assertSee('Total cost: $0.02')
            ->assertSee('Opted out: 1');

        $counts = $response->viewData('counts');
        $this->assertSame(1, $counts[SmsCampaignRecipient::STATE_DELIVERED]);
        $this->assertSame(1, $counts[SmsCampaignRecipient::STATE_SKIPPED]);
        $this->assertSame(1, $counts[SmsCampaignRecipient::STATE_NOT_SENT]);
        $this->assertSame(2, $campaign->fresh()->not_sent_count);
        $this->assertSame(1, $response->viewData('results')['opted_out']);
        $this->assertSame(2, $response->viewData('results')['cost_cents']);
    }

    public function test_in_flight_campaign_detail_uses_the_neutral_not_sent_label(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'status' => SmsCampaign::STATUS_SENDING,
            'recipient_count' => 3,
            'segment_count' => 1,
            'metered_cents' => 2,
            'not_sent_count' => 3,
            'created_by' => $owner->id,
            'sent_at' => now(),
        ]);

        $this->actingAs($owner)->get(route('campaigns.show', $campaign))
            ->assertOk()
            ->assertDontSee('Not sent (daily limit)')
            ->assertSee('Not sent: 3');

        $this->assertSame(3, $campaign->fresh()->not_sent_count);
    }
}
