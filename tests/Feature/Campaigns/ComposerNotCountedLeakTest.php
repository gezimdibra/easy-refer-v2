<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Models\Business;
use App\Models\Membership;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * D-1 hotfix pins (deploy49 re-check, VT-01 leak class): the
 * composer "Not counted:" line (FQ-07, Amendment 45) carried two
 * inline @if directives glued to the preceding word, so Blade
 * left them literal in the output — the rendered line showed raw
 * template syntax AND printed both recency clauses in every
 * state. These pins assert, at HTTP level, in both directions:
 * the exact rendered line in all four clause combinations, the
 * absence of every recency clause whose count is zero, and the
 * absence of template-syntax substrings from the visible text of
 * the composer AND its confirm twin.
 */
class ComposerNotCountedLeakTest extends TestCase
{
    use RefreshDatabase;

    private FakeMarketingSmsGateway $gateway;

    private int $phoneSequence = 9200;

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

    /** Visible text only: tags stripped, entities decoded, whitespace collapsed. */
    private function visibleText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function assertNoTemplateLeak(string $html): void
    {
        $visible = $this->visibleText($html);
        foreach (['@if', '@endif', '$exclusions', '{{', '}}'] as $needle) {
            $this->assertStringNotContainsString($needle, $visible, "Template syntax leaked into visible text: {$needle}");
        }
    }

    private function consentedMember(Business $business): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

        return $membership->fresh();
    }

    /** A member with no consent on file: renders the base "Not counted" sentence with zero recency skips. */
    private function noConsentMember(Business $business): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));

        return $membership->fresh();
    }

    private function touch(Business $business, Membership $membership, int $daysAgo): void
    {
        $campaign = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'Earlier sale',
            'status' => SmsCampaign::STATUS_SENT, 'created_by' => World::owner($business)->id,
        ]);
        $row = SmsCampaignRecipient::create([
            'campaign_id' => $campaign->id, 'membership_id' => $membership->id,
            'customer_id' => $membership->customer_id, 'phone_e164' => $membership->customer->phone_e164,
            'delivery_state' => 'delivered', 'segments' => 1, 'amount_cents' => 2,
        ]);
        $at = $this->now->copy()->subDays($daysAgo);
        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    private function spacingMember(Business $business): Membership
    {
        $member = $this->consentedMember($business);
        $this->touch($business, $member, 3);

        return $member;
    }

    private function cappedMember(Business $business): Membership
    {
        $member = $this->consentedMember($business);
        // 4 touches inside 30 days, the newest 8 days old: at the
        // volume cap, outside the 7-day spacing window.
        foreach ([28, 21, 14, 8] as $daysAgo) {
            $this->touch($business, $member, $daysAgo);
        }

        return $member;
    }

    private function draft(Business $business): SmsCampaign
    {
        $campaign = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'June sale',
            'status' => SmsCampaign::STATUS_DRAFT, 'created_by' => World::owner($business)->id,
        ]);
        app(CampaignSender::class)->stampFigures($campaign);

        return $campaign->fresh();
    }

    public function test_composer_zero_skip_state_renders_base_line_only_with_no_leak(): void
    {
        $business = World::business();
        $this->noConsentMember($business);
        $this->consentedMember($business);

        $response = $this->actingAs(World::owner($business))->get(route('campaigns.create'));
        $response->assertOk();
        $this->assertNoTemplateLeak($response->getContent());
        $visible = $this->visibleText($response->getContent());
        $this->assertStringContainsString(
            'Not counted: 0 added by you and not yet confirmed, 0 opted out, 1 with no consent on file.',
            $visible
        );
        $this->assertStringNotContainsString('texted in the last 7 days', $visible);
        $this->assertStringNotContainsString('at the most texts for now', $visible);
    }

    public function test_composer_spacing_only_state_renders_exactly_the_spacing_clause(): void
    {
        $business = World::business();
        $this->noConsentMember($business);
        $this->spacingMember($business);
        $this->consentedMember($business);

        $response = $this->actingAs(World::owner($business))->get(route('campaigns.create'));
        $response->assertOk();
        $this->assertNoTemplateLeak($response->getContent());
        $visible = $this->visibleText($response->getContent());
        $this->assertStringContainsString(
            'Not counted: 0 added by you and not yet confirmed, 0 opted out, 1 with no consent on file, 1 texted in the last 7 days.',
            $visible
        );
        $this->assertStringNotContainsString('at the most texts for now', $visible);
    }

    public function test_composer_cap_only_state_renders_exactly_the_cap_clause(): void
    {
        $business = World::business();
        $this->noConsentMember($business);
        $this->cappedMember($business);
        $this->consentedMember($business);

        $response = $this->actingAs(World::owner($business))->get(route('campaigns.create'));
        $response->assertOk();
        $this->assertNoTemplateLeak($response->getContent());
        $visible = $this->visibleText($response->getContent());
        $this->assertStringContainsString(
            'Not counted: 0 added by you and not yet confirmed, 0 opted out, 1 with no consent on file, 1 at the most texts for now.',
            $visible
        );
        $this->assertStringNotContainsString('texted in the last 7 days', $visible);
    }

    public function test_composer_both_skips_state_renders_both_clauses_in_order(): void
    {
        $business = World::business();
        $this->noConsentMember($business);
        $this->spacingMember($business);
        $this->cappedMember($business);
        $this->consentedMember($business);

        $response = $this->actingAs(World::owner($business))->get(route('campaigns.create'));
        $response->assertOk();
        $this->assertNoTemplateLeak($response->getContent());
        $visible = $this->visibleText($response->getContent());
        $this->assertStringContainsString(
            'Not counted: 0 added by you and not yet confirmed, 0 opted out, 1 with no consent on file, 1 texted in the last 7 days, 1 at the most texts for now.',
            $visible
        );
    }

    public function test_confirm_twin_line_is_leak_free_in_zero_skip_and_skipped_states(): void
    {
        // Zero-skip state.
        $business = World::business();
        $this->noConsentMember($business);
        $this->consentedMember($business);
        $campaign = $this->draft($business);

        $confirm = $this->actingAs(World::owner($business))->get(route('campaigns.confirm', $campaign));
        $confirm->assertOk();
        $this->assertNoTemplateLeak($confirm->getContent());
        $visible = $this->visibleText($confirm->getContent());
        $this->assertStringContainsString(
            'Not counted: 0 added by you and not yet confirmed, 0 opted out, 1 with no consent on file.',
            $visible
        );
        $this->assertStringNotContainsString('customers got a campaign text in the last 7 days and are skipped.', $visible);
        $this->assertStringNotContainsString('the most we send, and are skipped.', $visible);

        // Skipped state (spacing + cap): exactly the applicable
        // FQ-02/FQ-03 sentences, still no leak.
        $business2 = World::business();
        $this->noConsentMember($business2);
        $this->spacingMember($business2);
        $this->cappedMember($business2);
        $this->consentedMember($business2);
        $campaign2 = $this->draft($business2);

        $confirm2 = $this->actingAs(World::owner($business2))->get(route('campaigns.confirm', $campaign2));
        $confirm2->assertOk();
        $this->assertNoTemplateLeak($confirm2->getContent());
        $visible2 = $this->visibleText($confirm2->getContent());
        $this->assertStringContainsString(
            'Not counted: 0 added by you and not yet confirmed, 0 opted out, 1 with no consent on file.',
            $visible2
        );
        $this->assertStringContainsString('1 customer got a campaign text in the last 7 days and is skipped.', $visible2);
        $this->assertStringContainsString('1 customer has had 4 campaign texts in the last 30 days, the most we send, and are skipped.', $visible2);
    }
}
