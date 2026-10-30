<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Jobs\SendCampaignRecipient;
use App\Models\Business;
use App\Models\Membership;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsOptOut;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * FE-CAMP-01: one cost figure, two surfaces. The index reads
 * the campaign's stamped metered_cents (per-recipient × who
 * fit at send time); the detail reads the attempted sum. A
 * recipient who fit at stamp time but is G-14-skipped at job
 * time (opted out between queue and send) is never attempted,
 * so the stamped figure must settle to the attempted truth
 * when the campaign completes — and the index gains the
 * Skipped column so its columns account for every recipient.
 */
class CampaignCostAgreementTest extends TestCase
{
    use RefreshDatabase;

    private FakeMarketingSmsGateway $gateway;

    private int $phoneSequence = 4000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeMarketingSmsGateway;
        $this->app->instance(MarketingSmsGateway::class, $this->gateway);
    }

    private function member(Business $business): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        return $membership->fresh();
    }

    public function test_job_time_skip_settles_the_stamped_cost_to_the_attempted_sum(): void
    {
        Queue::fake();
        $business = World::business();
        $owner = World::owner($business);
        $skipped = $this->member($business);
        $sent = $this->member($business);

        $response = $this->actingAs($owner)->post(route('campaigns.store'), ['body' => 'Spring sale is on']);
        $response->assertRedirect();
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign));
        $this->actingAs($owner)->post(route('campaigns.send', $campaign));

        $campaign = $campaign->fresh();
        $this->assertSame(SmsCampaign::STATUS_SENDING, $campaign->status);
        // The send-time stamp counts everyone who fit: 2
        // recipients at 2 cents. That is the figure the index
        // shows while the campaign is in flight.
        $this->assertSame(4, $campaign->metered_cents);

        $rows = SmsCampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->get()->keyBy('membership_id');
        $this->assertCount(2, $rows);

        // The G-14 moment: one recipient opts out after
        // queueing, before their paced job runs.
        SmsOptOut::create(['phone_e164' => $skipped->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $sender = app(CampaignSender::class);
        foreach ($rows as $row) {
            (new SendCampaignRecipient($row->id))->handle($this->gateway, $sender);
        }

        $this->assertSame(SmsCampaignRecipient::STATE_SKIPPED, $rows[$skipped->id]->fresh()->delivery_state);
        $this->assertNull($rows[$skipped->id]->fresh()->sms_message_id);
        $this->assertSame(SmsCampaignRecipient::STATE_SENT, $rows[$sent->id]->fresh()->delivery_state);

        // The delivery callback lands; completion finalizes.
        $rows[$sent->id]->forceFill(['delivery_state' => SmsCampaignRecipient::STATE_DELIVERED])->save();
        $sender->finalizeIfComplete($campaign->fresh());

        $campaign = $campaign->fresh();
        $this->assertSame(SmsCampaign::STATUS_SENT, $campaign->status);
        // The stamped figure settled to the attempted truth:
        // one attempted send at 2 cents — the same figure the
        // detail page computes and the charger bills on.
        $this->assertSame(2, $campaign->metered_cents);

        $index = $this->actingAs($owner)->get(route('campaigns.index'));
        $index->assertOk();
        $index->assertSee('$0.02');
        $index->assertDontSee('$0.04');
        // The Skipped column accounts for the missing
        // recipient: 2 = 1 delivered + 0 failed + 1 skipped.
        $index->assertSee('Skipped');

        $detail = $this->actingAs($owner)->get(route('campaigns.show', $campaign));
        $detail->assertOk();
        $detail->assertSee('Total cost: $0.02');
        $detail->assertDontSee('Total cost: $0.04');
    }

    public function test_index_columns_account_for_every_recipient(): void
    {
        Queue::fake();
        $business = World::business();
        $owner = World::owner($business);
        $this->member($business);
        $this->member($business);
        $this->member($business);

        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'status' => SmsCampaign::STATUS_SENT,
            'recipient_count' => 3,
            'metered_cents' => 4,
            'created_by' => $owner->id,
            'sent_at' => now(),
        ]);

        $states = [
            SmsCampaignRecipient::STATE_DELIVERED,
            SmsCampaignRecipient::STATE_FAILED,
            SmsCampaignRecipient::STATE_SKIPPED,
        ];
        foreach ($states as $i => $state) {
            SmsCampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'membership_id' => Membership::query()->where('business_id', $business->id)->skip($i)->firstOrFail()->id,
                'customer_id' => Membership::query()->where('business_id', $business->id)->skip($i)->firstOrFail()->customer_id,
                'phone_e164' => '+1212555400'.$i,
                'delivery_state' => $state,
                'skip_reason' => $state === SmsCampaignRecipient::STATE_SKIPPED ? SmsCampaignRecipient::SKIP_OPTED_OUT : null,
                'segments' => 1,
                'amount_cents' => 2,
            ]);
        }

        $response = $this->actingAs($owner)->get(route('campaigns.index'));
        $response->assertOk();
        $response->assertSee('Skipped');

        $listed = $response->viewData('campaigns')->firstWhere('id', $campaign->id);
        $this->assertNotNull($listed);
        $this->assertSame(1, $listed->delivered_count);
        $this->assertSame(1, $listed->failed_count);
        $this->assertSame(1, $listed->skipped_count);
        // The columns account for every recipient.
        $this->assertSame(
            $listed->recipient_count,
            $listed->delivered_count + $listed->failed_count + $listed->skipped_count,
        );
    }
}
