<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Jobs\SendCampaignRecipient;
use App\Models\Business;
use App\Models\Membership;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsMessage;
use App\Models\SmsOptOut;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * The bulk SMS campaign surface (spec Sections 10.3, 10.4;
 * inventory DASH-09, DASH-10, SMS-04): compose with the locked
 * prefix and server-computed figures, the confirm screen as the
 * only send path, the re-confirm bounce when the numbers move,
 * the caps as recorded outcomes, the send-time re-check, tenant
 * isolation, and the status webhook leg.
 */
class CampaignsTest extends TestCase
{
    use RefreshDatabase;

    private FakeMarketingSmsGateway $gateway;

    private int $phoneSequence = 3000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeMarketingSmsGateway;
        $this->app->instance(MarketingSmsGateway::class, $this->gateway);
    }

    private function member(Business $business, array $consent = ['sms_consent' => true, 'consent_source' => 'customer_checkbox']): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill($consent)->save();

        return $membership->fresh();
    }

    private function draft(Business $business, string $body = 'Spring sale is on'): SmsCampaign
    {
        $owner = World::owner($business);
        $response = $this->actingAs($owner)->post(route('campaigns.store'), ['body' => $body]);
        $response->assertRedirect();

        return SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();
    }

    public function test_index_lists_own_campaigns_and_drafts_only(): void
    {
        $business = World::business();
        $other = World::business();
        $this->member($business);
        $this->draft($business, 'Our spring sale body');
        SmsCampaign::create([
            'business_id' => $other->id, 'body' => 'Other business secret body',
            'status' => SmsCampaign::STATUS_DRAFT, 'created_by' => World::owner($other)->id,
        ]);

        $response = $this->actingAs(World::owner($business))->get(route('campaigns.index'));

        $response->assertOk();
        $response->assertSee('Our spring sale body');
        $response->assertSee('Draft');
        $response->assertDontSee('Other business secret body');
    }

    public function test_compose_shows_dash_copy_audience_and_breakdown(): void
    {
        $business = World::business();
        $this->member($business);
        $this->member($business, ['sms_consent' => true, 'consent_source' => 'customer_reply']);
        $this->member($business, ['sms_consent' => true, 'consent_source' => 'owner_asserted']);
        $this->member($business, ['sms_consent' => false, 'consent_source' => 'customer_checkbox']);
        $out = $this->member($business);
        SmsOptOut::create(['phone_e164' => $out->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $response = $this->actingAs(World::owner($business))->get(route('campaigns.create'));

        $response->assertOk();
        $response->assertSee('Message');
        // R2 CAMP-COMPOSE: the button names the step it takes (review, not send).
        $response->assertSee('Review before sending');
        $response->assertSee('Sending to 2 customers who agreed to receive messages.');
        $response->assertSee('Only customers who agreed to receive text messages, and who have not opted out, will receive this.');
        $response->assertSee('added by you and not yet confirmed');
        $response->assertSee('no consent on file');
    }

    public function test_estimate_endpoint_returns_server_computed_figures(): void
    {
        $business = World::business();
        $this->member($business);
        $this->member($business);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->postJson(route('campaigns.estimate'), ['body' => 'Spring sale is on']);

        $response->assertOk();
        $response->assertJsonPath('segments', 1);
        $response->assertJsonPath('encoding', 'GSM-7');
        $response->assertJsonPath('per_recipient_cents', 2);
        $response->assertJsonPath('recipients', 2);
        $response->assertJsonPath('total_cents', 4);
        $response->assertJsonPath('rendered_body', $business->name.': Spring sale is on. Reply STOP to opt out.');

        $unicode = $this->actingAs($owner)->postJson(route('campaigns.estimate'), ['body' => 'Spring sale 🎉']);
        $unicode->assertJsonPath('encoding', 'Unicode');
    }

    public function test_estimate_and_store_share_the_body_length_cap(): void
    {
        $business = World::business();
        $this->member($business);
        $owner = World::owner($business);

        // 671 UTF-16 units: the store refuses it, so the live
        // counter must refuse it too, with the store's own error,
        // instead of pricing a message that cannot be saved
        // (FB-03).
        $tooLong = str_repeat('a', 671);
        $estimate = $this->actingAs($owner)->postJson(route('campaigns.estimate'), ['body' => $tooLong]);
        $estimate->assertStatus(422);
        $estimate->assertJsonValidationErrors('body');
        $this->assertSame('This message is too long. Shorten it and try again.', $estimate->json('errors.body.0'));

        $store = $this->actingAs($owner)->post(route('campaigns.store'), ['body' => $tooLong]);
        $store->assertSessionHasErrors('body');
        $this->assertSame(0, SmsCampaign::query()->count());

        // The cap counts UTF-16 units, not characters: 336 emoji
        // are 336 characters but 672 units.
        $emoji = $this->actingAs($owner)->postJson(route('campaigns.estimate'), ['body' => str_repeat('🎉', 336)]);
        $emoji->assertStatus(422);

        // Exactly at the cap, both surfaces accept the body.
        $atCap = str_repeat('a', 670);
        $this->actingAs($owner)->postJson(route('campaigns.estimate'), ['body' => $atCap])->assertOk();
        $this->actingAs($owner)->post(route('campaigns.store'), ['body' => $atCap])->assertRedirect();
    }

    public function test_store_creates_a_draft_and_redirects_to_confirm(): void
    {
        $business = World::business();
        $this->member($business);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->post(route('campaigns.store'), ['body' => 'Spring sale is on']);

        $campaign = SmsCampaign::query()->where('business_id', $business->id)->firstOrFail();
        $response->assertRedirect(route('campaigns.confirm', $campaign));
        $this->assertSame(SmsCampaign::STATUS_DRAFT, $campaign->status);
        $this->assertSame(1, $campaign->recipient_count);
        $this->assertSame(1, $campaign->segment_count);
        $this->assertSame(2, $campaign->metered_cents);
        $this->assertSame($owner->id, $campaign->created_by);
    }

    public function test_store_rejects_an_empty_body(): void
    {
        $business = World::business();
        $this->member($business);

        $response = $this->actingAs(World::owner($business))
            ->post(route('campaigns.store'), ['body' => '']);

        $response->assertSessionHasErrors('body');
        $this->assertSame(0, SmsCampaign::query()->count());
    }

    public function test_confirm_shows_the_money_block_and_the_cap_sentence(): void
    {
        $business = World::business();
        $this->member($business);
        $this->member($business);
        $campaign = $this->draft($business, str_repeat('a', 500));

        $response = $this->actingAs(World::owner($business))->get(route('campaigns.confirm', $campaign));

        $response->assertOk();
        $response->assertSee('Sending to 2 customers who agreed to receive messages.');
        $response->assertSee('4 segments');
        $response->assertSee('long message');
        $response->assertSee('$0.16');
        $response->assertSee('The $99 monthly cap covers visit charges only');
        // RS-14 (GEN-05) supersedes the CAMP-01 'Send now' on
        // this screen: the button restates the scope.
        $response->assertSee('Send to 2 customers');
    }

    public function test_send_dispatches_recipients_and_writes_evidence(): void
    {
        $business = World::business();
        $a = $this->member($business);
        $b = $this->member($business);
        $this->member($business, ['sms_consent' => true, 'consent_source' => 'owner_asserted']);
        $campaign = $this->draft($business);
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign));

        $response = $this->actingAs($owner)->post(route('campaigns.send', $campaign));

        $response->assertRedirect(route('campaigns.show', $campaign));
        $campaign->refresh();
        // Recipients sit in `sent` awaiting delivery callbacks,
        // so the campaign is still `sending` (design completion
        // rule: no rows left in queued or sent).
        $this->assertSame(SmsCampaign::STATUS_SENDING, $campaign->status);
        $this->assertNotNull($campaign->sent_at);
        $this->assertSame(2, $campaign->recipient_count);

        $rows = SmsCampaignRecipient::query()->where('campaign_id', $campaign->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(SmsCampaignRecipient::STATE_SENT, $row->delivery_state);
            $this->assertNotNull($row->sms_message_id);
            $this->assertSame(2, $row->amount_cents);
        }
        $this->assertSame(
            [$a->customer->phone_e164, $b->customer->phone_e164],
            collect($this->gateway->sent)->pluck('to')->all()
        );
        $this->assertSame(
            $business->name.': Spring sale is on. Reply STOP to opt out.',
            $this->gateway->sent[0]['body']
        );
        $message = SmsMessage::query()->where('purpose', 'campaign')->firstOrFail();
        $this->assertSame('marketing', $message->messaging_service);
        $this->assertSame('customer_checkbox', $message->consent_basis);
    }

    public function test_send_bounces_back_to_confirm_when_the_numbers_moved(): void
    {
        $business = World::business();
        $this->member($business);
        $second = $this->member($business);
        $campaign = $this->draft($business);
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign));

        // Consent moves between the confirm view and the send.
        $second->forceFill(['sms_consent' => false])->save();

        $response = $this->actingAs($owner)->post(route('campaigns.send', $campaign));

        $response->assertRedirect(route('campaigns.confirm', $campaign));
        $this->assertSame(0, SmsCampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
        $campaign->refresh();
        $this->assertSame(SmsCampaign::STATUS_DRAFT, $campaign->status);
        $this->assertSame(1, $campaign->recipient_count);
        $this->assertCount(0, $this->gateway->sent);
    }

    public function test_double_submit_is_a_no_op(): void
    {
        $business = World::business();
        $this->member($business);
        $campaign = $this->draft($business);
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign));
        $this->actingAs($owner)->post(route('campaigns.send', $campaign));

        $response = $this->actingAs($owner)->post(route('campaigns.send', $campaign));

        $response->assertRedirect(route('campaigns.show', $campaign));
        $this->assertSame(1, SmsCampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
        $this->assertCount(1, $this->gateway->sent);
    }

    public function test_racing_sends_on_one_draft_send_exactly_once(): void
    {
        Queue::fake();

        $business = World::business();
        $this->member($business);
        $this->member($business, ['sms_consent' => true, 'consent_source' => 'customer_reply']);
        $campaign = $this->draft($business);
        app(CampaignSender::class)->stampFigures($campaign);

        // Two requests in flight at once each loaded the campaign
        // while it was still a draft; neither has seen the other's
        // status flip when its send() runs (FB-01).
        $requestA = SmsCampaign::findOrFail($campaign->id);
        $requestB = SmsCampaign::findOrFail($campaign->id);

        $sender = app(CampaignSender::class);
        $first = $sender->send($requestA);
        $second = $sender->send($requestB);

        $this->assertSame('sent', $first['outcome']);
        $this->assertSame('already', $second['outcome']);
        $this->assertSame(2, SmsCampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
        Queue::assertPushed(SendCampaignRecipient::class, 2);
    }

    public function test_daily_cap_trims_the_overflow_to_not_sent(): void
    {
        Config::set('campaigns.daily_cap', 3);
        $business = World::business();
        for ($i = 0; $i < 5; $i++) {
            $this->member($business);
        }
        $campaign = $this->draft($business);
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign));

        $this->actingAs($owner)->post(route('campaigns.send', $campaign));

        $campaign->refresh();
        $this->assertSame(5, $campaign->recipient_count);
        $this->assertSame(2, $campaign->not_sent_count);
        $this->assertSame(6, $campaign->metered_cents);
        $this->assertSame(3, SmsCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('delivery_state', SmsCampaignRecipient::STATE_SENT)->count());
        $notSent = SmsCampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('delivery_state', SmsCampaignRecipient::STATE_NOT_SENT)->get();
        $this->assertCount(2, $notSent);
        $this->assertSame(SmsCampaignRecipient::SKIP_DAILY_CAP, $notSent[0]->skip_reason);
        $this->assertCount(3, $this->gateway->sent);
    }

    public function test_confirm_shows_the_daily_cap_line_when_the_cap_will_trim(): void
    {
        Config::set('campaigns.daily_cap', 3);
        $business = World::business();
        for ($i = 0; $i < 5; $i++) {
            $this->member($business);
        }
        $campaign = $this->draft($business);

        $response = $this->actingAs(World::owner($business))->get(route('campaigns.confirm', $campaign));

        $response->assertOk();
        $response->assertSee('You can send 3 of 5 today. The rest will not be sent.');
    }

    public function test_platform_ceiling_pauses_the_send(): void
    {
        Config::set('campaigns.platform_daily_ceiling', 1);
        $business = World::business();
        $this->member($business);
        $this->member($business);
        $campaign = $this->draft($business);
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign));

        $response = $this->actingAs($owner)->post(route('campaigns.send', $campaign));

        $response->assertRedirect(route('campaigns.confirm', $campaign));
        $this->assertSame(0, SmsCampaignRecipient::query()->where('campaign_id', $campaign->id)->count());
        $this->assertSame(SmsCampaign::STATUS_DRAFT, $campaign->fresh()->status);
        $this->assertCount(0, $this->gateway->sent);
    }

    public function test_send_time_recheck_skips_opted_out_and_revoked(): void
    {
        Queue::fake();
        $business = World::business();
        $a = $this->member($business);
        $b = $this->member($business);
        $c = $this->member($business);
        $campaign = $this->draft($business);
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign));
        $this->actingAs($owner)->post(route('campaigns.send', $campaign));

        $rows = SmsCampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->get()->keyBy('membership_id');
        $this->assertCount(3, $rows);

        // Ground truth moves after queueing, before the sends.
        SmsOptOut::create(['phone_e164' => $a->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);
        $b->forceFill(['sms_consent' => false])->save();

        $sender = app(CampaignSender::class);
        foreach ($rows as $row) {
            (new SendCampaignRecipient($row->id))->handle($this->gateway, $sender);
        }

        $this->assertSame(SmsCampaignRecipient::STATE_SKIPPED, $rows[$a->id]->fresh()->delivery_state);
        $this->assertSame(SmsCampaignRecipient::SKIP_OPTED_OUT, $rows[$a->id]->fresh()->skip_reason);
        $this->assertSame(SmsCampaignRecipient::STATE_SKIPPED, $rows[$b->id]->fresh()->delivery_state);
        $this->assertSame(SmsCampaignRecipient::SKIP_CONSENT_REVOKED, $rows[$b->id]->fresh()->skip_reason);
        $this->assertSame(SmsCampaignRecipient::STATE_SENT, $rows[$c->id]->fresh()->delivery_state);
        $this->assertCount(1, $this->gateway->sent);
        $this->assertSame($c->customer->phone_e164, $this->gateway->sent[0]['to']);
        // Skipped recipients were never attempted: no evidence
        // row, so the charger can never bill them.
        $this->assertNull($rows[$a->id]->fresh()->sms_message_id);
        $this->assertNull($rows[$b->id]->fresh()->sms_message_id);
    }

    public function test_cross_tenant_campaigns_are_404_everywhere(): void
    {
        $business = World::business();
        $other = World::business();
        $this->member($other);
        $campaign = $this->draft($other);
        $stranger = World::owner($business);

        $this->actingAs($stranger)->get(route('campaigns.confirm', $campaign))->assertNotFound();
        $this->actingAs($stranger)->get(route('campaigns.show', $campaign))->assertNotFound();
        $this->actingAs($stranger)->get(route('campaigns.status', $campaign))->assertNotFound();
        $this->actingAs($stranger)->post(route('campaigns.send', $campaign))->assertNotFound();
        $this->actingAs($stranger)->delete(route('campaigns.destroy', $campaign))->assertNotFound();
    }

    public function test_destroy_removes_a_draft_but_never_a_sent_campaign(): void
    {
        $business = World::business();
        $this->member($business);
        $owner = World::owner($business);
        $draft = $this->draft($business);

        $this->actingAs($owner)->delete(route('campaigns.destroy', $draft))
            ->assertRedirect(route('campaigns.index'));
        $this->assertSame(0, SmsCampaign::query()->where('id', $draft->id)->count());

        $sent = $this->draft($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $sent));
        $this->actingAs($owner)->post(route('campaigns.send', $sent));

        $this->actingAs($owner)->delete(route('campaigns.destroy', $sent))->assertNotFound();
        $this->assertSame(1, SmsCampaign::query()->where('id', $sent->id)->count());
    }

    public function test_status_endpoint_and_detail_masking(): void
    {
        $business = World::business();
        $member = $this->member($business);
        $campaign = $this->draft($business);
        $owner = World::owner($business);
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign));
        $this->actingAs($owner)->post(route('campaigns.send', $campaign));

        $status = $this->actingAs($owner)->getJson(route('campaigns.status', $campaign));
        $status->assertOk();
        $status->assertJsonPath('status', SmsCampaign::STATUS_SENDING);
        $status->assertJsonPath('total', 1);
        $status->assertJsonPath('counts.sent', 1);

        $show = $this->actingAs($owner)->get(route('campaigns.show', $campaign));
        $show->assertOk();
        $phone = $member->customer->phone_e164;
        $show->assertSee('*** *** '.substr($phone, -4));
        $show->assertDontSee($phone);
        $show->assertSee('Sending 1 of 1');
    }

    public function test_status_webhook_advances_the_recipient_and_completes_the_campaign(): void
    {
        Config::set('services.twilio.webhook_auth_token', 'test-auth-token');
        $business = World::business();
        $member = $this->member($business);
        $campaign = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Spring sale is on',
            'recipient_count' => 1, 'segment_count' => 1, 'metered_cents' => 2,
            'status' => SmsCampaign::STATUS_SENDING, 'created_by' => World::owner($business)->id,
            'sent_at' => now(),
        ]);
        $message = SmsMessage::create([
            'business_id' => $business->id, 'customer_id' => $member->customer_id,
            'phone_e164' => $member->customer->phone_e164, 'purpose' => 'campaign',
            'messaging_service' => 'marketing', 'template_key' => 'campaign',
            'rendered_body' => 'Spring sale is on', 'twilio_message_sid' => 'SM_CAMPAIGN_1',
            'delivery_state' => 'sent', 'consent_basis' => 'customer_checkbox',
            'opt_out_state_at_send' => false,
        ]);
        $recipient = SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'membership_id' => $member->id,
            'customer_id' => $member->customer_id, 'phone_e164' => $member->customer->phone_e164,
            'twilio_message_sid' => 'SM_CAMPAIGN_1', 'sms_message_id' => $message->id,
            'delivery_state' => SmsCampaignRecipient::STATE_SENT, 'segments' => 1, 'amount_cents' => 2,
        ]);

        $this->signedWebhookPost(['MessageSid' => 'SM_CAMPAIGN_1', 'MessageStatus' => 'delivered'])
            ->assertOk();

        $this->assertSame(SmsCampaignRecipient::STATE_DELIVERED, $recipient->fresh()->delivery_state);
        $this->assertSame(SmsCampaign::STATUS_SENT, $campaign->fresh()->status);
    }

    public function test_status_webhook_records_the_provider_error_code_on_failure(): void
    {
        Config::set('services.twilio.webhook_auth_token', 'test-auth-token');
        $business = World::business();
        $member = $this->member($business);
        $campaign = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Spring sale is on',
            'recipient_count' => 1, 'segment_count' => 1, 'metered_cents' => 2,
            'status' => SmsCampaign::STATUS_SENDING, 'created_by' => World::owner($business)->id,
            'sent_at' => now(),
        ]);
        $message = SmsMessage::create([
            'business_id' => $business->id, 'customer_id' => $member->customer_id,
            'phone_e164' => $member->customer->phone_e164, 'purpose' => 'campaign',
            'messaging_service' => 'marketing', 'template_key' => 'campaign',
            'rendered_body' => 'Spring sale is on', 'twilio_message_sid' => 'SM_CAMPAIGN_2',
            'delivery_state' => 'sent', 'consent_basis' => 'customer_checkbox',
            'opt_out_state_at_send' => false,
        ]);
        $recipient = SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'membership_id' => $member->id,
            'customer_id' => $member->customer_id, 'phone_e164' => $member->customer->phone_e164,
            'twilio_message_sid' => 'SM_CAMPAIGN_2', 'sms_message_id' => $message->id,
            'delivery_state' => SmsCampaignRecipient::STATE_SENT, 'segments' => 1, 'amount_cents' => 2,
        ]);

        $this->signedWebhookPost([
            'MessageSid' => 'SM_CAMPAIGN_2', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30005',
        ])->assertOk();

        $fresh = $recipient->fresh();
        $this->assertSame(SmsCampaignRecipient::STATE_FAILED, $fresh->delivery_state);
        $this->assertSame('30005', $fresh->provider_error_code);
        $this->assertSame('failed', $message->fresh()->delivery_state);
        $this->assertSame(SmsCampaign::STATUS_SENT, $campaign->fresh()->status);
    }

    public function test_unavailable_gateway_renders_the_graceful_state(): void
    {
        // No fake bound in this test: the test environment has no
        // Twilio configuration, so the null gateway is bound.
        $this->app->forgetInstance(MarketingSmsGateway::class);
        $business = World::business();
        $this->member($business);
        $owner = World::owner($business);

        $response = $this->actingAs($owner)->get(route('campaigns.create'));
        $response->assertOk();
        $response->assertSee('Text campaigns are not available right now.');
        $response->assertDontSee('Send to all customers');

        $store = $this->actingAs($owner)->post(route('campaigns.store'), ['body' => 'Spring sale is on']);
        $store->assertRedirect(route('campaigns.index'));
        $this->assertSame(0, SmsCampaign::query()->count());
    }

    private function signedWebhookPost(array $params): \Illuminate\Testing\TestResponse
    {
        $url = route('webhooks.twilio.status');
        $validator = new RequestValidator('test-auth-token');
        $signature = $validator->computeSignature($url, $params);

        return $this->call('POST', $url, $params, [], [], [
            'HTTP_X_TWILIO_SIGNATURE' => $signature,
        ]);
    }
}
