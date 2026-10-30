<?php

namespace Tests\Feature\Owner;

use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Contracts\MarketingSmsGateway;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * R2 money + settings redesign pins (P6 train, design pack
 * redesign/r2-money-settings/design.md sections 1 to 12).
 * Presentation only: ordering, pills, tiles, banners, ledes.
 * Counts and semantics are pinned unchanged by the suites that
 * already own them; these pins name the new presentation.
 */
class R2RedesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
    }

    public function test_settings_shell_drops_breadcrumb_and_adds_pack_ledes(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $expectations = [
            'settings.billing' => 'Your plan, your card, and what you have been charged.',
            'settings.account' => 'Who signs in to this account, and how to end your program.',
            'security' => 'Your sign-in email, password, two-factor sign-in, and devices.',
            'settings.rewards' => 'Your reward, and the timing.',
            'settings.reviews' => 'Where happy customers review you in public.',
            'settings.value' => 'About what a customer spends on one visit at your store. Your Overview uses it to estimate the sales your visits brought in.',
        ];

        foreach ($expectations as $route => $lede) {
            $this->actingAs($owner)->get(route($route))
                ->assertOk()
                ->assertSee($lede)
                ->assertDontSee('aria-label="Breadcrumb"', false);
        }
    }

    public function test_campaigns_index_is_cards_with_accounted_tiles_and_drafts_first(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        $sent = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Sent campaign body',
            'status' => SmsCampaign::STATUS_SENT, 'created_by' => $owner->id,
            'recipient_count' => 4, 'metered_cents' => 16, 'sent_at' => now()->subDay(),
        ]);
        $mk = function (string $state) use ($business, $sent) {
            [$membership] = World::membership($business, World::customer('+1212555'.random_int(1000, 9999)));
            SmsCampaignRecipient::create([
                'campaign_id' => $sent->id, 'membership_id' => $membership->id,
                'customer_id' => $membership->customer_id,
                'phone_e164' => $membership->customer->phone_e164, 'delivery_state' => $state,
                'segments' => 1, 'amount_cents' => 2,
            ]);
        };
        $mk(SmsCampaignRecipient::STATE_DELIVERED);
        $mk(SmsCampaignRecipient::STATE_DELIVERED);
        $mk(SmsCampaignRecipient::STATE_FAILED);
        $mk(SmsCampaignRecipient::STATE_SKIPPED);

        SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Draft campaign body',
            'status' => SmsCampaign::STATUS_DRAFT, 'created_by' => $owner->id,
            'recipient_count' => 0, 'metered_cents' => 0,
        ]);

        $html = $this->actingAs($owner)->get(route('campaigns.index'))->assertOk()->getContent();

        // No table survives; every number carries its label.
        $this->assertStringNotContainsString('<table', $html);
        foreach (['Recipients', 'Delivered', 'Failed', 'Skipped', 'Cost'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        // Accounting: delivered + failed + skipped = recipients.
        $this->assertStringContainsString('Sent campaign body', $html);
        // Draft leads the list even though it is newer work.
        $this->assertLessThan(
            strpos($html, 'Sent campaign body'),
            strpos($html, 'Draft campaign body')
        );
        // Status is a pill, not a bare word in a cell.
        $this->assertMatchesRegularExpression('/pill[^"]*">Sent/', $html);
        $this->assertMatchesRegularExpression('/pill[^"]*">Draft/', $html);
    }

    public function test_compose_has_phone_preview_cost_tiles_and_truthful_button(): void
    {
        $business = World::business();
        [$membership] = World::membership($business, World::customer('+12125550101'));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        $html = $this->actingAs(World::owner($business))->get(route('campaigns.create'))->assertOk()->getContent();

        $this->assertStringContainsString('data-phone-preview', $html);
        $this->assertStringContainsString('Review before sending', $html);
        $this->assertStringNotContainsString('Send to all customers', $html);
        foreach (['Customers', 'Segments each', 'Total'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        // Presets are a quiet section, not a panel.
        $this->assertStringContainsString('data-presets-quiet', $html);
    }

    public function test_confirm_is_a_receipt_with_banner_exclusions(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        [$membership] = World::membership($business, World::customer('+12125550102'));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();
        [$out] = World::membership($business, World::customer('+12125550103'));
        $out->forceFill(['sms_consent' => true, 'consent_source' => 'owner_asserted'])->save();

        $campaign = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Receipt check body',
            'status' => SmsCampaign::STATUS_DRAFT, 'created_by' => $owner->id,
        ]);

        $html = $this->actingAs($owner)->get(route('campaigns.confirm', $campaign))->assertOk()->getContent();

        $this->assertStringContainsString('data-phone-preview', $html);
        $this->assertStringContainsString('Segments per customer', $html);
        $this->assertStringContainsString('Price per segment', $html);
        $this->assertStringContainsString('banner banner-warning', $html);
        $this->assertStringContainsString('Not counted: 1 added by you and not yet confirmed', $html);
        // The scoped confirmation sentence and button stay verbatim.
        $this->assertStringContainsString('Once sent, this text cannot be unsent.', $html);
    }

    public function test_campaign_detail_results_are_tiles_with_honesty_banner_and_folded_reasons(): void
    {
        $business = World::business();
        $owner = World::owner($business);
        $campaign = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Detail body',
            'status' => SmsCampaign::STATUS_SENT, 'created_by' => $owner->id,
            'sent_at' => now(), 'segment_count' => 1, 'metered_cents' => 4,
        ]);
        [$membership] = World::membership($business, World::customer('+12125550104'));
        SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'membership_id' => $membership->id,
            'customer_id' => $membership->customer_id,
            'phone_e164' => $membership->customer->phone_e164,
            'delivery_state' => SmsCampaignRecipient::STATE_FAILED,
            'provider_error_code' => '30005', 'segments' => 1, 'amount_cents' => 2,
        ]);

        $html = $this->actingAs($owner)->get(route('campaigns.show', $campaign))->assertOk()->getContent();

        $this->assertStringContainsString('banner banner-info', $html);
        $this->assertStringContainsString('This counts visits that happened after the text. It does not prove the text caused them.', $html);
        $this->assertStringNotContainsString('What happened', $html);
        $this->assertStringContainsString('This number is unknown or no longer in service.', $html);
        $this->assertMatchesRegularExpression('/pill[^"]*">Failed/', $html);
    }

    public function test_review_queue_is_one_oldest_first_queue_with_count_line_and_pills(): void
    {
        $business = World::business();

        $referrer = World::customer('+12125550999', 'Reg', 'Ular');
        [$membership] = World::membership($business, $referrer);
        $friend = World::customer('+12125550777', 'Fri', 'End');
        [$newerReferral] = World::referral($membership, $friend, [
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now()->subDays(3),
        ]);

        [$redeemed] = World::referral($membership, World::customer('+12125550778', 'Two', 'Friend'), [
            'state' => Referral::STATE_REDEEMED, 'redeemed_at' => now()->subDays(10),
        ]);
        World::reward($membership, $redeemed, [
            'reward_text_snapshot' => '$10 off', 'state' => Reward::STATE_AVAILABLE,
            'hold' => true, 'hold_rules' => ['velocity_daily'], 'held_at' => now()->subDays(9),
            'notification_state' => 'queued',
        ]);

        $html = $this->actingAs(World::owner($business))->get(route('reviews'))->assertOk()->getContent();

        $this->assertStringContainsString('2 waiting', $html);
        $this->assertStringContainsString('oldest 9 days', $html);
        // The 9-day reward precedes the 3-day referral.
        $this->assertLessThan(strpos($html, 'referred by Reg'), strpos($html, "Reg's reward:"));
        $this->assertMatchesRegularExpression('/pill[^"]*">Reward/', $html);
        $this->assertMatchesRegularExpression('/pill[^"]*">Referral/', $html);
        // Age at 7+ days warns.
        $this->assertMatchesRegularExpression('/pill pill-warning[^"]*">Waiting 9 days/', $html);
    }

    public function test_billing_leads_with_forecast_tiles_wallet_row_and_charge_pills(): void
    {
        $business = World::business(['billing_status' => 'active', 'free_visits_remaining' => 0]);
        $owner = World::owner($business);
        PaymentMethod::create([
            'business_id' => $business->id, 'square_card_id' => 'card_test_1',
            'card_brand' => 'VISA', 'last4' => '5858', 'exp_month' => 3, 'exp_year' => 2028,
            'is_default' => true, 'state' => PaymentMethod::STATE_SUCCEEDED,
            'idempotency_key' => 'card_'.$business->id.'_r2',
        ]);
        DB::table('billing_records')->insert([
            'business_id' => $business->id, 'kind' => 'per_referral_batch',
            'state' => 'succeeded', 'amount_cents' => 1800, 'idempotency_key' => 'r2_record_1',
            'period_start' => now()->subMonth()->startOfMonth(), 'period_end' => now()->subMonth()->endOfMonth(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->actingAs($owner)->get(route('settings.billing'))->assertOk()->getContent();

        // Merge ruling (2026-10-26): the stop-billing disclosure
        // stays on Billing (FE-CANCEL interim ruling); the R2
        // draft's removal was reversed at merge.
        $this->assertStringContainsString('To stop billing, end your program in Settings, under Account. Your final bill covers the visits and texts you already have.', $html);
        $this->assertStringNotContainsString('Built up so far:', $html);
        $this->assertStringNotContainsString('Card on file ending 5858. Managed securely by Square.', $html);
        $this->assertStringContainsString('•••• 5858', $html);
        $this->assertMatchesRegularExpression('/pill pill-success[^"]*">On file/', $html);
        $this->assertMatchesRegularExpression('/pill pill-success[^"]*">Paid/', $html);
        foreach (['Visits counted', 'Visit fees so far', 'Free visits left'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('tile-value-sm', $html);
    }

    public function test_billing_past_due_and_no_card_wear_banner_chrome(): void
    {
        $pastDue = World::business(['billing_status' => 'past_due']);
        $html = $this->actingAs(World::owner($pastDue))->get(route('settings.billing'))->assertOk()->getContent();
        $this->assertStringContainsString('banner banner-error', $html);

        $noCard = World::business(['billing_status' => 'active']);
        $html = $this->actingAs(World::owner($noCard))->get(route('settings.billing'))->assertOk()->getContent();
        $this->assertStringContainsString('banner banner-warning', $html);
        // RE-DERIVED (activation redesign, census C-18,
        // ACT-BILL-03a): the banner names the free visits it
        // is true for.
        $this->assertStringContainsString('No card on file. Visits keep counting while your free visits last (5 left), and the amount builds up here.', $html);
    }

    public function test_account_page_leads_with_identity_and_error_close_heading(): void
    {
        $business = World::business(['name' => 'Harbor Street Barbers']);
        $owner = World::owner($business);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $html = $this->actingAs($owner->fresh())->get(route('settings.account'))->assertOk()->getContent();

        $this->assertStringContainsString('Harbor Street Barbers', $html);
        $this->assertStringContainsString('Business account · signed in as the owner', $html);
        // The shipped email-state partial still renders beside the address.
        $this->assertStringContainsString('Verified', $html);
        $this->assertStringContainsString('Change email', $html);
        $this->assertMatchesRegularExpression('/<h2[^>]*text-error[^>]*>End my program/', $html);
    }

    public function test_security_page_orders_2fa_first_with_pill_human_sessions_and_consequence_line(): void
    {
        $business = World::business();
        $owner = World::owner($business);

        DB::table('sessions')->insert([
            'id' => 'r2-other-session', 'user_id' => $owner->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'payload' => '', 'last_activity' => now()->subHours(2)->timestamp,
        ]);

        $html = $this->actingAs($owner)->get(route('security'))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Change password'), strpos($html, 'Two-factor authentication'));
        $this->assertLessThan(strpos($html, 'Change email'), strpos($html, 'Active sessions'));
        $this->assertMatchesRegularExpression('/pill[^"]*">Off/', $html);
        $this->assertStringContainsString('Safari on iPhone', $html);
        // The full UA survives only inside the row's title
        // attribute (design section 9, for support): exactly one
        // occurrence, never as the visible device identity.
        $this->assertSame(1, substr_count($html, 'Mozilla/5.0'));
        $this->assertStringNotContainsString('<td>Mozilla', $html);
        $this->assertStringContainsString('This signs you out on this device too.', $html);
        // Humanized time, never the raw timestamp shape.
        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $html);
    }

    public function test_rewards_settings_preview_groups_and_pack_strip_when_set(): void
    {
        $business = World::business([
            'friend_reward_text' => 'Free brow wax',
            'referrer_reward_text' => '$10 off your next visit',
        ]);

        $html = $this->actingAs(World::owner($business))->get(route('settings.rewards'))->assertOk()->getContent();

        $this->assertStringContainsString('The offer', $html);
        $this->assertStringContainsString('The timing', $html);
        $this->assertStringContainsString('What customers see', $html);
        $this->assertStringContainsString('Free brow wax', $html);
        $this->assertStringContainsString('Starter packs · 3 ready-made rewards, if you ever want a change', $html);
    }

    public function test_reviews_settings_show_state_row_and_where_it_appears(): void
    {
        $business = World::business(['google_review_url' => 'https://g.page/r/example/review']);
        $html = $this->actingAs(World::owner($business))->get(route('settings.reviews'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/pill[^"]*">Link set/', $html);
        $this->assertStringContainsString('https://g.page/r/example/review', $html);
        $this->assertStringContainsString('Customers see this link on their receipt after a visit, and again when they claim a reward, at most once every 90 days for each customer.', $html);

        $unset = World::business();
        $html = $this->actingAs(World::owner($unset))->get(route('settings.reviews'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/pill[^"]*">Not set \(optional\)/', $html);
    }

    public function test_value_settings_example_line_is_bound_to_the_field(): void
    {
        $business = World::business(['average_ticket_cents' => 3500]);
        $html = $this->actingAs(World::owner($business))->get(route('settings.value'))->assertOk()->getContent();
        $this->assertStringContainsString('At $35 a visit, 10 visits estimate about $350 in estimated sales on your Overview.', $html);
        $this->assertStringContainsString('data-value-example', $html);
    }
}
