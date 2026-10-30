<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Jobs\SendCampaignRecipient;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsMessage;
use App\Models\SmsOptOut;
use App\Services\Campaigns\CampaignResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * RK-02 (smart-build-queue.md §5, Train 3): the campaign
 * closed-loop report. A sent campaign's page gains a Results
 * block (Amendment 23, CAMP-22/23): delivered, failed, opted
 * out, cost, and the recipients who visited or used a reward
 * within 7 and 14 days of the text, labelled honestly as visits
 * AFTER the text; causation wording never renders. One action:
 * resend to the failed recipients who are still eligible, with
 * the audience re-derived through CampaignAudience at send
 * time, never from the stored recipient list.
 */
class CampaignResultsTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSequence = 700;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
    }

    private function member(Business $business): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        return $membership->fresh();
    }

    private function recipient(SmsCampaign $campaign, Membership $membership, string $state, array $overrides = []): SmsCampaignRecipient
    {
        // Attempted rows carry the SmsMessage the job created
        // (the recipient's sms_message_id is an FK to it); a
        // skipped row was never attempted and carries none.
        $messageId = null;
        if ($state !== SmsCampaignRecipient::STATE_SKIPPED) {
            $messageId = SmsMessage::create([
                'business_id' => $campaign->business_id,
                'customer_id' => $membership->customer_id,
                'phone_e164' => $membership->customer->phone_e164,
                'purpose' => 'campaign',
                'messaging_service' => 'marketing',
                'template_key' => 'campaign',
                'rendered_body' => 'Seeded campaign message',
                'twilio_message_sid' => 'SM_seed_'.$membership->id,
                'delivery_state' => $state,
            ])->id;
        }

        return SmsCampaignRecipient::create(array_merge([
            'campaign_id' => $campaign->id,
            'membership_id' => $membership->id,
            'customer_id' => $membership->customer_id,
            'phone_e164' => $membership->customer->phone_e164,
            'delivery_state' => $state,
            'segments' => 1,
            'amount_cents' => 2,
            'sms_message_id' => $messageId,
        ], $overrides));
    }

    /**
     * The seeded campaign from the acceptance description: sent
     * 21 days ago. A visited at +3d (a redeemed referral), B at
     * +10d (a claimed reward), C at +20d (outside both windows),
     * D failed and still eligible, E failed and since opted out,
     * F skipped as opted out at the send's re-check, G delivered
     * and never visited.
     *
     * @return array{campaign: SmsCampaign, members: array<string, Membership>}
     */
    private function seededCampaign(Business $business): array
    {
        $owner = World::owner($business);
        $sentAt = now()->subDays(21);

        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => $owner->id,
            'sent_at' => $sentAt,
            'recipient_count' => 7,
            'segment_count' => 1,
            'metered_cents' => 12,
        ]);

        $members = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $key) {
            $members[$key] = $this->member($business);
        }

        $this->recipient($campaign, $members['a'], SmsCampaignRecipient::STATE_DELIVERED);
        $this->recipient($campaign, $members['b'], SmsCampaignRecipient::STATE_DELIVERED);
        $this->recipient($campaign, $members['c'], SmsCampaignRecipient::STATE_DELIVERED);
        $this->recipient($campaign, $members['d'], SmsCampaignRecipient::STATE_FAILED);
        $this->recipient($campaign, $members['e'], SmsCampaignRecipient::STATE_FAILED);
        $this->recipient($campaign, $members['f'], SmsCampaignRecipient::STATE_SKIPPED, [
            'skip_reason' => SmsCampaignRecipient::SKIP_OPTED_OUT,
        ]);
        $this->recipient($campaign, $members['g'], SmsCampaignRecipient::STATE_DELIVERED);

        // A: a friend A referred visited 3 days after the text.
        [$referralA] = World::referral($members['a'], World::customer('+12125550801'));
        $referralA->forceFill([
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => $sentAt->copy()->addDays(3),
        ])->save();

        // B: B used a reward 10 days after the text.
        [$referralB] = World::referral($members['b'], World::customer('+12125550802'));
        [$rewardB] = World::reward($members['b'], $referralB);
        $rewardB->forceFill([
            'state' => Reward::STATE_CLAIMED,
            'claimed_at' => $sentAt->copy()->addDays(10),
        ])->save();

        // C: a friend C referred visited 20 days after the text:
        // outside both windows, counted in neither.
        [$referralC] = World::referral($members['c'], World::customer('+12125550803'));
        $referralC->forceFill([
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => $sentAt->copy()->addDays(20),
        ])->save();

        // E opted out AFTER the text (feeds the results Opted
        // out count and excludes E from any resend).
        SmsOptOut::create([
            'phone_e164' => $members['e']->customer->phone_e164,
            'opted_out_at' => $sentAt->copy()->addDays(2),
            'source' => 'carrier_stop',
        ]);
        // F's opt-out predates the send (the send's re-check
        // found it; the row above is its record).
        SmsOptOut::create([
            'phone_e164' => $members['f']->customer->phone_e164,
            'opted_out_at' => $sentAt->copy()->subDay(),
            'source' => 'carrier_stop',
        ]);

        return ['campaign' => $campaign, 'members' => $members];
    }

    public function test_results_block_counts_windows_and_honest_labels(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        ['campaign' => $campaign] = $this->seededCampaign($business);

        $html = $this->actingAs($owner)->get(route('campaigns.show', $campaign))->assertOk()->getContent();

        $this->assertStringContainsString('Results', $html);
        // R2 CAMP-DETAIL: delivered and failed are tiles now
        // (label and value in separate elements).
        $this->assertStringContainsString('Delivered', $html);
        $this->assertStringContainsString('Failed', $html);
        // F (skipped at the send's re-check) + E (STOP after the text).
        $this->assertStringContainsString('Opted out: 2', $html);
        $this->assertStringContainsString('Total cost: $0.12', $html);

        // Windowed visits: A inside both windows, B inside 14
        // only, C outside both, and each recipient counts once.
        // R2 CAMP-DETAIL: the visit windows are tiles now.
        $this->assertStringContainsString('Visited within 7 days', $html);
        $this->assertStringContainsString('Visited within 14 days', $html);
        $this->assertStringContainsString(
            'This counts visits that happened after the text. It does not prove the text caused them.',
            $html
        );

        // Causation wording never renders (absence-pinned).
        $this->assertStringNotContainsString('caused by', $html);
        $this->assertStringNotContainsString('brought in', $html);
        $this->assertStringNotContainsString('thanks to this text', $html);
    }

    /**
     * M4 (Train-3 review, test hygiene): the both-sources
     * dedupe in CampaignResults::visitedWithin, folded in from
     * the review lane's probe as a standing pin. A membership
     * that visited via BOTH sources — a friend they referred
     * visited AND they claimed a reward, both inside the
     * window — counts ONCE in each window, never twice; the
     * naive per-source sum for this fixture is 3 (H twice,
     * I once), the deduplicated count is 2.
     */
    public function test_membership_visiting_via_both_sources_counts_once_in_each_window(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $sentAt = now()->subDays(21);

        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Spring sale is on',
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => $owner->id,
            'sent_at' => $sentAt,
            'recipient_count' => 2,
            'segment_count' => 1,
            'metered_cents' => 4,
        ]);

        $both = $this->member($business);
        $referralOnly = $this->member($business);
        $this->recipient($campaign, $both, SmsCampaignRecipient::STATE_DELIVERED);
        $this->recipient($campaign, $referralOnly, SmsCampaignRecipient::STATE_DELIVERED);

        // H visited via BOTH sources, 3 days after the text:
        // a redeemed referral they referred...
        [$bothReferral] = World::referral($both, World::customer('+12125550811'));
        $bothReferral->forceFill([
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => $sentAt->copy()->addDays(3),
        ])->save();

        // ...and a reward they claimed (minted off a second
        // referral, as the review probe shaped it).
        [$rewardReferral] = World::referral($both, World::customer('+12125550812'));
        [$bothReward] = World::reward($both, $rewardReferral);
        $bothReward->forceFill([
            'state' => Reward::STATE_CLAIMED,
            'claimed_at' => $sentAt->copy()->addDays(3),
        ])->save();

        // Control: I visited via the referral source only, also
        // at +3d, so the windows must count I once as well.
        [$controlReferral] = World::referral($referralOnly, World::customer('+12125550813'));
        $controlReferral->forceFill([
            'state' => Referral::STATE_REDEEMED,
            'redeemed_at' => $sentAt->copy()->addDays(3),
        ])->save();

        $results = CampaignResults::forCampaign($campaign);

        $this->assertSame(2, $results['visited_7'], 'A both-sources membership must count once in the 7-day window.');
        $this->assertSame(2, $results['visited_14'], 'A both-sources membership must count once in the 14-day window.');
    }

    public function test_resend_reaches_only_still_eligible_failed_recipients(): void
    {
        Queue::fake();

        $business = World::business();
        $owner = World::owner($business);
        ['campaign' => $campaign, 'members' => $members] = $this->seededCampaign($business);

        // The resend block names its current scope: of the two
        // failed recipients only D is still eligible (E opted
        // out after the text).
        $html = $this->actingAs($owner)->get(route('campaigns.show', $campaign))->assertOk()->getContent();
        $this->assertStringContainsString('The customers whose texts failed can be tried again. Only those who still agree to texts are included.', $html);
        $this->assertStringContainsString('Resend to 1 customer', $html);
        $this->assertStringNotContainsString('Resend to 1 customers', $html);

        $response = $this->actingAs($owner)->post(route('campaigns.resend', $campaign));
        $response->assertRedirect();

        $resend = SmsCampaign::query()
            ->where('business_id', $business->id)
            ->whereKeyNot($campaign->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($campaign->body, $resend->body);
        $response->assertRedirect(route('campaigns.show', $resend));

        // Exactly one recipient row: D. E is excluded by the
        // send-time audience re-derivation, and nobody who got
        // the text (delivered/skipped) is re-texted.
        $recipients = $resend->recipients()->get();
        $this->assertCount(1, $recipients);
        $this->assertSame($members['d']->id, $recipients->sole()->membership_id);
        $this->assertSame(SmsCampaignRecipient::STATE_QUEUED, $recipients->sole()->delivery_state);
        Queue::assertPushed(SendCampaignRecipient::class, 1);

        // The source campaign is untouched.
        $this->assertSame(7, $campaign->recipients()->count());
    }

    public function test_resend_with_nobody_eligible_creates_nothing(): void
    {
        Queue::fake();

        $business = World::business();
        $owner = World::owner($business);
        ['campaign' => $campaign, 'members' => $members] = $this->seededCampaign($business);

        // D opts out too: now no failed recipient can be resent.
        SmsOptOut::create([
            'phone_e164' => $members['d']->customer->phone_e164,
            'opted_out_at' => now(),
            'source' => 'carrier_stop',
        ]);

        $html = $this->actingAs($owner)->get(route('campaigns.show', $campaign))->assertOk()->getContent();
        $this->assertStringContainsString('None of the failed numbers can receive texts anymore.', $html);

        $this->actingAs($owner)->post(route('campaigns.resend', $campaign))
            ->assertRedirect(route('campaigns.show', $campaign))
            ->assertSessionHas('status', 'None of the failed numbers can receive texts anymore.');

        $this->assertSame(1, SmsCampaign::query()->where('business_id', $business->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_draft_campaign_has_no_results_block_and_no_resend(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->member($business);

        $draft = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'Not sent yet',
            'status' => SmsCampaign::STATUS_DRAFT,
            'created_by' => $owner->id,
        ]);

        $html = $this->actingAs($owner)->get(route('campaigns.show', $draft))->assertOk()->getContent();
        $this->assertStringNotContainsString('Visited within 7 days of the text', $html);

        $this->actingAs($owner)->post(route('campaigns.resend', $draft))->assertNotFound();
        $this->assertSame(1, SmsCampaign::query()->where('business_id', $business->id)->count());
    }
}
