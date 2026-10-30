<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Jobs\SendCampaignRecipient;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsOptOut;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignResults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * ID-03 (Amendment 46): the win-back segment campaign end to
 * end — preset -> draft (audience_segment stored) -> estimate
 * -> confirm branches -> send -> job re-check -> duplicate /
 * resend. The segment intersects INSIDE the consent + F1
 * audience; a segment member skipped by F1 is skipped with
 * the F1 reason named, never double-counted.
 */
class WinBackSegmentCampaignTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 7100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
        Queue::fake();
    }

    private function member(Business $business, array $consent = ['sms_consent' => true, 'consent_source' => 'customer_checkbox']): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill($consent)->save();

        return $membership->fresh();
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

    /** A prior campaign touch (a recipient row) for F1 / cooldown fixtures. */
    private function touch(Business $business, Membership $m, int $daysAgo, bool $winback = false, string $state = SmsCampaignRecipient::STATE_DELIVERED): void
    {
        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => $winback ? 'It has been a while since you sent a friend our way. Your referral link still works. Share it with a friend: when they visit, you both get a reward.' : 'Earlier sale',
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => World::owner($business)->id,
            'audience_segment' => $winback ? 'lapsed_referrers' : null,
            'sent_at' => now()->subDays($daysAgo),
        ]);
        $row = SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $m->id,
            'customer_id' => $m->customer_id,
            'phone_e164' => $m->customer->phone_e164,
            'delivery_state' => $state,
            'segments' => 2,
            'amount_cents' => 4,
        ]);
        $row->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        $campaign->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
    }

    public function test_preset_creates_a_segment_draft_with_the_winback_body_and_lands_on_confirm(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->lapsed($business);

        $this->actingAs($owner)->get(route('campaigns.create'))
            ->assertOk()
            ->assertSee('Win back past referrers')
            ->assertSee('Past referrers are customers who sent a friend who visited, whose referral link is still active and who have no visit on the way, and who have not sent a new referral in 45 days or more.')
            ->assertSee('1 past referrer can get this text now.');

        $response = $this->actingAs($owner)->post(route('campaigns.preset'), ['preset' => 'win_back_referrers']);
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        $response->assertRedirect(route('campaigns.confirm', $campaign));
        $this->assertSame('lapsed_referrers', $campaign->audience_segment);
        $this->assertSame(
            'It has been a while since you sent a friend our way. Your referral link still works. Share it with a friend: when they visit, you both get a reward.',
            $campaign->body,
        );
        // No link and no count in the body.
        $this->assertStringNotContainsString('http', $campaign->body);
    }

    public function test_composer_zero_line_when_the_segment_is_empty(): void
    {
        $business = World::business();
        $this->member($business);

        $this->actingAs(World::owner($business))->get(route('campaigns.create'))
            ->assertOk()
            // TS3-10 re-derivation (Train A5, Amendment 52):
            // the composer zero form is scoped to who can get
            // the text; the shipped "quiet right now" form
            // survives only on the confirm's WB-Z branch, where
            // segment-empty is the proven cause.
            ->assertSee('No past referrers can get a win-back text right now.')
            ->assertDontSee('past referrers can get this text now');
    }

    public function test_confirm_is_segment_scoped_and_send_reaches_only_the_segment(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $lapsed = $this->lapsed($business);
        $this->lapsed($business);
        $this->member($business); // consented, not a referrer: never in the segment audience
        $this->member($business);

        $this->actingAs($owner)->post(route('campaigns.preset'), ['preset' => 'win_back_referrers']);
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign))
            ->assertOk()
            ->assertSee('This text goes only to past referrers: 2 customers.')
            // WB-09 REPLACES the whole-list scope sentence on
            // a segment draft (both rendering would double-scope).
            ->assertDontSee('Sending to 2 customers who agreed to receive messages.');

        $this->actingAs($owner)->post(route('campaigns.send', $campaign))->assertRedirect();
        $campaign->refresh();
        $this->assertSame(SmsCampaign::STATUS_SENDING, $campaign->status);
        $this->assertSame(2, $campaign->recipients()->count());
        $this->assertEqualsCanonicalizing(
            [$lapsed->id, $campaign->recipients()->orderBy('id')->skip(1)->first()->membership_id],
            $campaign->recipients()->pluck('membership_id')->all(),
        );
        Queue::assertPushed(SendCampaignRecipient::class, 2);
    }

    public function test_segment_member_skipped_by_f1_is_named_with_the_f1_reason_not_double_counted(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $recent = $this->lapsed($business);
        $this->touch($business, $recent, 3); // a general campaign touch 3 days ago: F1 spacing
        $this->lapsed($business);

        $audience = CampaignAudience::forBusiness($business, 'lapsed_referrers');
        $this->assertSame(1, $audience['memberships']->count());
        $this->assertSame(1, $audience['exclusions']['recently_texted_spacing']);
        $this->assertSame(0, $audience['exclusions']['winback_cooldown']);
        $this->assertSame(2, $audience['segment_size']);

        $this->actingAs($owner)->post(route('campaigns.preset'), ['preset' => 'win_back_referrers']);
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign))
            ->assertOk()
            ->assertSee('1 customer got a campaign text in the last 7 days and is skipped.');
    }

    public function test_winback_cooldown_excludes_for_90_days_and_boundary_clears(): void
    {
        $business = World::business();
        $m = $this->lapsed($business);
        $this->touch($business, $m, 30, winback: true);

        $audience = CampaignAudience::forBusiness($business, 'lapsed_referrers');
        $this->assertSame(0, $audience['memberships']->count());
        $this->assertSame(1, $audience['exclusions']['winback_cooldown']);

        // At exactly 90 days the touch no longer binds.
        \Illuminate\Support\Carbon::setTestNow(now()->addDays(60));
        $audience = CampaignAudience::forBusiness($business, 'lapsed_referrers');
        $this->assertSame(1, $audience['memberships']->count());
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_cooldown_is_phone_keyed_remove_and_readd_inherits_it(): void
    {
        $business = World::business();
        $m = $this->lapsed($business);
        $this->touch($business, $m, 10, winback: true);
        $phone = $m->customer->phone_e164;
        $m->forceFill(['removed_at' => now()])->save();

        // A fresh membership on the same phone: the cooldown
        // history is the phone's. (It also lacks qualification
        // until it refers; the audience read must not error and
        // must not include it.)
        [$readded] = World::membership($business, World::customer($phone));
        $readded->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        $audience = CampaignAudience::forBusiness($business, 'lapsed_referrers');
        $this->assertSame(0, $audience['memberships']->count());
    }

    public function test_confirm_wb_z_branch_when_segment_empty(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->member($business);

        $this->actingAs($owner)->post(route('campaigns.preset'), ['preset' => 'win_back_referrers']);
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign))
            ->assertOk()
            ->assertSee('Total $0.00 for 0 customers. No past referrers are quiet right now.')
            ->assertSee('This draft stays saved. Past referrers go quiet 45 days after their last referral.')
            ->assertDontSee("today's limit")
            ->assertDontSee('No customers have confirmed yet')
            ->assertDontSee('Send to 0 customers');
    }

    public function test_confirm_cooldown_zero_branch_names_its_own_cause_and_date(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $m = $this->lapsed($business);
        $this->touch($business, $m, 30, winback: true);

        $this->actingAs($owner)->post(route('campaigns.preset'), ['preset' => 'win_back_referrers']);
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        $expectedDate = $business->localTime(now()->subDays(30)->addDays(90))->format('M j, Y');
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign))
            ->assertOk()
            ->assertSee('Total $0.00 for 0 customers. Every past referrer who is quiet was sent a win-back text in the last 90 days.')
            ->assertSee('The first of them can get a win-back text again starting '.$expectedDate.'.')
            ->assertDontSee("today's limit");
    }

    // ---------- Condition 1 (two-key): zero-branch cause accounting ----------

    public function test_confirm_cooldown_zero_with_a_coexisting_opt_out_scopes_its_sentence(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $cooled = $this->lapsed($business);
        $this->touch($business, $cooled, 30, winback: true);
        $optedOut = $this->lapsed($business);
        SmsOptOut::create(['phone_e164' => $optedOut->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $audience = CampaignAudience::forBusiness($business, 'lapsed_referrers');
        $this->assertSame(0, $audience['memberships']->count());
        $this->assertSame(1, $audience['exclusions']['winback_cooldown']);
        $this->assertSame(1, $audience['exclusions']['opted_out']);

        $this->actingAs($owner)->post(route('campaigns.preset'), ['preset' => 'win_back_referrers']);
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        $expectedDate = $business->localTime(now()->subDays(30)->addDays(90))->format('M j, Y');
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign))
            ->assertOk()
            // Scoped form: only the cooldown group is claimed
            // for the cooldown; the opted-out member is quiet
            // for a different cause and is NOT swept into an
            // "every past referrer" cooldown claim.
            ->assertSee('Total $0.00 for 0 customers. 1 past referrer was sent a win-back text in the last 90 days.')
            ->assertDontSee('Every past referrer who is quiet was sent a win-back text')
            ->assertSee('The first of them can get a win-back text again starting '.$expectedDate.'.');
    }

    public function test_confirm_branch4_mixed_partition_names_two_disjoint_groups(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $f1Only = $this->lapsed($business);
        $this->touch($business, $f1Only, 3); // general touch: F1 spacing only
        $cooldownOnly = $this->lapsed($business);
        $this->touch($business, $cooldownOnly, 30, winback: true, state: SmsCampaignRecipient::STATE_FAILED);

        $audience = CampaignAudience::forBusiness($business, 'lapsed_referrers');
        $this->assertSame(0, $audience['memberships']->count());
        $this->assertSame(1, $audience['exclusions']['recently_texted_spacing']);
        $this->assertSame(1, $audience['exclusions']['winback_cooldown']);
        $this->assertSame(2, $audience['segment_size']);

        $this->actingAs($owner)->post(route('campaigns.preset'), ['preset' => 'win_back_referrers']);
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        // The F1 group returns first (touch 3 days ago + 7):
        // disjoint groups, so the earliest return across the
        // partition is the first date anyone can be sent to.
        $expectedDate = $business->localTime(now()->subDays(3)->addDays(7))->format('M j, Y');
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign))
            ->assertOk()
            ->assertSee('Total $0.00 for 0 customers. 1 past referrer got a campaign text too recently.')
            ->assertSee('1 other past referrer was sent a win-back text in the last 90 days.')
            ->assertDontSee('Every customer who agreed to texts got a campaign text too recently')
            ->assertDontSee('were also sent a win-back text')
            ->assertSee('Some customers can get texts again starting '.$expectedDate.'.');
    }

    public function test_composer_wb06_names_the_consent_cause_when_no_temporal_clause_applies(): void
    {
        $business = World::business();
        $optedOut = $this->lapsed($business);
        SmsOptOut::create(['phone_e164' => $optedOut->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $this->actingAs(World::owner($business))->get(route('campaigns.create'))
            ->assertOk()
            ->assertSee('No past referrers can get this text now.')
            ->assertSee('1 opted out.')
            ->assertDontSee('were sent a win-back text in the last 90 days.')
            ->assertDontSee('got a campaign text too recently.');
    }

    // ---------- Condition 2 (two-key): resend exception + failed-ledger divergence ----------

    public function test_resend_own_send_exception_reaches_only_the_sources_failed_recipient(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $failed = $this->lapsed($business);
        $delivered = $this->lapsed($business);
        $neverSent = $this->lapsed($business);

        $source = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'It has been a while since you sent a friend our way. Your referral link still works. Share it with a friend: when they visit, you both get a reward.',
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => $owner->id,
            'audience_segment' => 'lapsed_referrers',
            'sent_at' => now()->subDays(10),
        ]);
        foreach ([[$failed, SmsCampaignRecipient::STATE_FAILED], [$delivered, SmsCampaignRecipient::STATE_DELIVERED]] as [$member, $state]) {
            $row = SmsCampaignRecipient::create([
                'campaign_id' => $source->id,
                'membership_id' => $member->id,
                'customer_id' => $member->customer_id,
                'phone_e164' => $member->customer->phone_e164,
                'delivery_state' => $state,
                'segments' => 2,
                'amount_cents' => 4,
            ]);
            $row->forceFill(['created_at' => now()->subDays(10)])->save();
        }

        // The source's own failed touch would bind the
        // cooldown without the exemption; with it, exactly the
        // failed recipient — never the delivered one, never a
        // segment member the source never sent to — is in scope.
        $this->assertSame([$failed->id], CampaignResults::forCampaign($source)['resend_ids']);

        $response = $this->actingAs($owner)->post(route('campaigns.resend', $source));
        $resend = SmsCampaign::query()->where('business_id', $business->id)->whereKeyNot($source->id)->latest('id')->firstOrFail();
        $response->assertRedirect(route('campaigns.show', $resend));
        $this->assertSame('lapsed_referrers', $resend->audience_segment);
        $this->assertSame($source->id, $resend->winback_exempt_campaign_id);
        $this->assertSame([$failed->id], $resend->recipients()->pluck('membership_id')->all());
        $this->assertSame(SmsCampaignRecipient::STATE_QUEUED, $resend->recipients()->sole()->delivery_state);
        Queue::assertPushed(SendCampaignRecipient::class, 1);

        // Fail-closed: re-resending the SAME source cannot
        // re-blast — the first resend's own (non-exempt) touch
        // now suppresses the recipient, so the scope is empty
        // and no third campaign is created.
        $this->actingAs($owner)->post(route('campaigns.resend', $source))
            ->assertRedirect(route('campaigns.show', $source))
            ->assertSessionHas('status', 'None of the failed numbers can receive texts anymore.');
        $this->assertSame(2, SmsCampaign::query()->where('business_id', $business->id)->count());
        Queue::assertPushed(SendCampaignRecipient::class, 1);
    }

    public function test_failed_winback_touch_counts_for_the_cooldown_but_not_for_f1_on_the_same_fixture(): void
    {
        $business = World::business();
        $failedMember = $this->lapsed($business);
        $this->touch($business, $failedMember, 10, winback: true, state: SmsCampaignRecipient::STATE_FAILED);
        $undeliveredMember = $this->lapsed($business);
        $this->touch($business, $undeliveredMember, 10, winback: true, state: SmsCampaignRecipient::STATE_UNDELIVERED);

        // The win-back ledger counts both attempts: neither
        // member is sendable to a win-back.
        $segment = CampaignAudience::forBusiness($business, 'lapsed_referrers');
        $this->assertSame(0, $segment['memberships']->count());
        $this->assertSame(2, $segment['exclusions']['winback_cooldown']);
        $this->assertTrue(CampaignAudience::winbackTouchedByOtherCampaign($business, $failedMember->customer->phone_e164, 999999));

        // F1's ledger counts neither: on the whole list both
        // members remain eligible, with no recency exclusion,
        // and the F1 job backstop sees no touch.
        $whole = CampaignAudience::forBusiness($business);
        $this->assertEqualsCanonicalizing(
            [$failedMember->id, $undeliveredMember->id],
            $whole['memberships']->pluck('id')->all(),
        );
        $this->assertSame(0, $whole['exclusions']['recently_texted_spacing']);
        $this->assertSame(0, $whole['exclusions']['recently_texted_cap']);
        $this->assertFalse(CampaignAudience::recentlyTouchedByOtherCampaign($business, $failedMember->customer->phone_e164, 999999));
        $this->assertFalse(CampaignAudience::recentlyTouchedByOtherCampaign($business, $undeliveredMember->customer->phone_e164, 999999));
    }

    public function test_segment_draft_confirm_blocks_send_while_flow_inactive(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->lapsed($business);
        $this->actingAs($owner)->post(route('campaigns.preset'), ['preset' => 'win_back_referrers']);
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        $business->forceFill(['friend_reward_text' => ''])->save();

        // A fresh owner instance: the acting owner's cached
        // business relation predates the text change (in
        // production each request loads its own).
        $owner = World::owner($business->fresh());
        $this->actingAs($owner)->get(route('campaigns.confirm', $campaign->fresh()))
            ->assertOk()
            // Strengthened at the TS3-09 re-derivation (Train
            // A5): the absence pin names the singular button
            // form too, so a rendered send button cannot slip
            // past it under the new grammar.
            ->assertDontSee('Send to 1 customer');
        // And the send POST itself cannot proceed past a
        // segment send while inactive: it bounces with no rows.
        // (The sender's segment re-derivation reaches zero
        // eligible only if the audience changed; the flow gate
        // on confirm is the design's named guard, and the
        // sender-level flow check for segment drafts:)
        $this->actingAs($owner)->post(route('campaigns.send', $campaign));
        $this->assertSame(0, $campaign->recipients()->count());
    }

    public function test_estimate_prices_the_segment_for_a_segment_draft_and_the_whole_list_otherwise(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->lapsed($business);
        $this->member($business);
        $this->member($business);

        $this->actingAs($owner)->post(route('campaigns.preset'), ['preset' => 'win_back_referrers']);
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        $this->actingAs($owner)->postJson(route('campaigns.estimate'), ['body' => 'Hello there', 'draft_id' => $campaign->id])
            ->assertOk()->assertJsonPath('recipients', 1);
        $this->actingAs($owner)->postJson(route('campaigns.estimate'), ['body' => 'Hello there'])
            ->assertOk()->assertJsonPath('recipients', 3);
    }

    public function test_duplicate_carries_the_segment_with_the_body(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->lapsed($business);
        $original = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'It has been a while since you sent a friend our way. Your referral link still works. Share it with a friend: when they visit, you both get a reward.',
            'status' => SmsCampaign::STATUS_SENT,
            'created_by' => $owner->id,
            'audience_segment' => 'lapsed_referrers',
        ]);

        $this->actingAs($owner)->post(route('campaigns.duplicate', $original));
        $copy = SmsCampaign::query()->where('business_id', $business->id)->whereKeyNot($original->id)->latest('id')->firstOrFail();
        $this->assertSame('lapsed_referrers', $copy->audience_segment);
        $this->actingAs($owner)->get(route('campaigns.confirm', $copy))
            ->assertOk()
            ->assertSee('This text goes only to past referrers: 1 customer.');
    }

    public function test_job_time_recheck_skips_a_recipient_who_referred_after_the_send(): void
    {
        $business = World::business();
        $m = $this->lapsed($business);
        $campaign = SmsCampaign::create([
            'business_id' => $business->id,
            'body' => 'It has been a while since you sent a friend our way. Your referral link still works. Share it with a friend: when they visit, you both get a reward.',
            'status' => SmsCampaign::STATUS_SENDING,
            'created_by' => World::owner($business)->id,
            'audience_segment' => 'lapsed_referrers',
            'sent_at' => now(),
        ]);
        $row = SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'membership_id' => $m->id,
            'customer_id' => $m->customer_id,
            'phone_e164' => $m->customer->phone_e164,
            'delivery_state' => SmsCampaignRecipient::STATE_QUEUED,
            'segments' => 2,
            'amount_cents' => 4,
        ]);

        // The referrer refers again before their job runs:
        // they are no longer lapsed.
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$referral] = World::referral($m, World::customer($phone));

        (new SendCampaignRecipient($row->id))->handle(app(MarketingSmsGateway::class), app(\App\Services\Campaigns\CampaignSender::class));

        $row->refresh();
        $this->assertSame(SmsCampaignRecipient::STATE_SKIPPED, $row->delivery_state);
        $this->assertSame(SmsCampaignRecipient::SKIP_NO_LONGER_LAPSED, $row->skip_reason);
        $this->assertNull($row->sms_message_id);
    }

    public function test_editing_a_segment_draft_never_widens_its_audience(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $this->lapsed($business);
        $this->member($business);
        $this->member($business);

        $this->actingAs($owner)->post(route('campaigns.preset'), ['preset' => 'win_back_referrers']);
        $campaign = SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();

        // Back to edit: the composer for this draft prices the
        // segment audience, and saving keeps the segment.
        $this->actingAs($owner)->get(route('campaigns.create', ['draft' => $campaign->id]))
            ->assertOk()
            ->assertSee('Audience: past referrers (1)');
        $this->actingAs($owner)->post(route('campaigns.store'), ['draft_id' => $campaign->id, 'body' => 'A custom win-back note from the owner.']);
        $campaign->refresh();
        $this->assertSame('lapsed_referrers', $campaign->audience_segment);
        $this->assertSame(1, $campaign->recipient_count);
    }
}
