<?php

namespace Tests\Feature\Owner;

use App\Contracts\MarketingSmsGateway;
use App\Models\Business;
use App\Models\Membership;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * FE-LBL-01 (Copy Amendment 25): three labels that made
 * correct numbers read as wrong ones. The dashboard's
 * completed-only tile says so ("Referrals completed"); the
 * QR placements panel names its window ("Totals are all
 * time."); the campaign resend button pluralizes.
 */
class FreshEyesLabelTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSequence = 5000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
    }

    public function test_dashboard_tile_names_the_completed_only_count(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550985'));
        [$referral] = World::referral($membership, World::customer('+12125550111'));
        $referral->forceFill(['state' => \App\Models\Referral::STATE_REDEEMED, 'redeemed_at' => now()])->save();

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Referrals completed');
        $response->assertDontSee('<p class="tile-label">Referrals</p>', false);
    }

    public function test_qr_placements_panel_names_its_all_time_window(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('owner.qr'));

        $response->assertOk();
        $response->assertSee('Totals are all time.');
    }

    public function test_resend_button_pluralizes_one_and_many(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $one = $this->sentCampaignWithFailures($business, $owner, 1);
        $html = $this->actingAs($owner)->get(route('campaigns.show', $one))->assertOk()->getContent();
        $this->assertStringContainsString('Resend to 1 customer', $html);
        $this->assertStringNotContainsString('Resend to 1 customers', $html);

        $two = $this->sentCampaignWithFailures($business, $owner, 2);
        $html = $this->actingAs($owner)->get(route('campaigns.show', $two))->assertOk()->getContent();
        $this->assertStringContainsString('Resend to 2 customers', $html);
    }

    private function sentCampaignWithFailures(Business $business, $owner, int $failures): SmsCampaign
    {
        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => $owner->id,
            'sent_at' => now()->subDay(),
            'recipient_count' => $failures,
            'segment_count' => 1,
            'metered_cents' => 2 * $failures,
        ]);

        for ($i = 0; $i < $failures; $i++) {
            $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
            [$membership] = World::membership($business, World::customer($phone));
            $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();
            $membership = $membership->fresh();

            $messageId = SmsMessage::create([
                'business_id' => $business->id,
                'customer_id' => $membership->customer_id,
                'phone_e164' => $membership->customer->phone_e164,
                'purpose' => 'campaign',
                'messaging_service' => 'marketing',
                'template_key' => 'campaign',
                'rendered_body' => 'Seeded campaign message',
                'twilio_message_sid' => 'SM_lbl_'.$membership->id,
                'delivery_state' => SmsCampaignRecipient::STATE_FAILED,
            ])->id;

            SmsCampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'membership_id' => $membership->id,
                'customer_id' => $membership->customer_id,
                'phone_e164' => $membership->customer->phone_e164,
                'delivery_state' => SmsCampaignRecipient::STATE_FAILED,
                'segments' => 1,
                'amount_cents' => 2,
                'sms_message_id' => $messageId,
            ]);
        }

        return $campaign;
    }
}
