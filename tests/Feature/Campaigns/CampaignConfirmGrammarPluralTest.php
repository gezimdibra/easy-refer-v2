<?php

namespace Tests\Feature\Campaigns;

use App\Contracts\MarketingSmsGateway;
use App\Models\BillingEvent;
use App\Models\Business;
use App\Models\Membership;
use App\Models\Referral;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\SmsOptOut;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeMarketingSmsGateway;
use Tests\Support\World;
use Tests\TestCase;

/**
 * GRAMMAR MICRO-FIX pins (r51 Gate 3 (c), CONFIRMED): four
 * count-1 pluralization defects on the campaign confirm
 * surface. Each sentence is pinned at count 1 with its FULLY
 * singular form (subject-verb agreement included), the
 * ungrammatical count-1 string is pinned ABSENT, and counts
 * 0 and 2 are pinned byte-identical to the pre-fix rendering
 * (at 0 the clause/line does not render at all).
 *
 * The four sentences (confirm.blade.php):
 *  S1 banner win-back clause: "{n} customers were sent a
 *     win-back text in the last 90 days and are skipped."
 *  S2 banner spacing clause (FQ-02): "{n} customers got a
 *     campaign text in the last 7 days and are skipped."
 *  S3 segment scope line: "This text goes only to past
 *     referrers: {n} customers."
 *  S4 zero-sendable cooldown form: "Total $0.00 for 0
 *     customers. {n} past referrers were sent a win-back
 *     text in the last 90 days."
 *
 * Assertions run on the visible text (tags stripped,
 * whitespace collapsed) so each pin is an exact rendered
 * line, per the D-1 lesson — never a loose substring of a
 * longer line that could hide a neighbour's wording.
 */
class CampaignConfirmGrammarPluralTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 3300;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MarketingSmsGateway::class, new FakeMarketingSmsGateway);
        Queue::fake();
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

    private function member(Business $business): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->seq++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill(['sms_consent' => true, 'consent_source' => 'customer_checkbox'])->save();

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
    private function touch(Business $business, Membership $m, int $daysAgo, bool $winback = false): void
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
            'delivery_state' => SmsCampaignRecipient::STATE_DELIVERED,
            'segments' => 2,
            'amount_cents' => 4,
        ]);
        $at = $this->now->copy()->subDays($daysAgo);
        $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        $campaign->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    private function wholeListDraft(Business $business): SmsCampaign
    {
        $campaign = SmsCampaign::create([
            'business_id' => $business->id, 'body' => 'June sale',
            'status' => SmsCampaign::STATUS_DRAFT, 'created_by' => World::owner($business)->id,
        ]);
        app(CampaignSender::class)->stampFigures($campaign);

        return $campaign->fresh();
    }

    private function segmentDraft(Business $business): SmsCampaign
    {
        $this->actingAs(World::owner($business))->post(route('campaigns.preset'), ['preset' => 'win_back_referrers']);

        return SmsCampaign::query()->where('business_id', $business->id)->latest('id')->firstOrFail();
    }

    private function confirmVisible(Business $business, SmsCampaign $campaign): string
    {
        $response = $this->actingAs(World::owner($business))->get(route('campaigns.confirm', $campaign));
        $response->assertOk();

        return $this->visibleText($response->getContent());
    }

    // ---------- S1: banner win-back clause ----------

    public function test_s1_winback_banner_clause_is_singular_at_one(): void
    {
        $business = World::business();
        $cooled = $this->lapsed($business);
        $this->touch($business, $cooled, 10, winback: true);
        $this->lapsed($business); // one sendable segment member

        $visible = $this->confirmVisible($business, $this->segmentDraft($business));

        $this->assertStringContainsString(
            '1 customer was sent a win-back text in the last 90 days and is skipped.',
            $visible
        );
        $this->assertStringNotContainsString(
            '1 customers were sent a win-back text in the last 90 days and are skipped.',
            $visible
        );
    }

    public function test_s1_winback_banner_clause_is_plural_at_two_and_absent_at_zero(): void
    {
        $business = World::business();
        foreach ([10, 12] as $daysAgo) {
            $cooled = $this->lapsed($business);
            $this->touch($business, $cooled, $daysAgo, winback: true);
        }
        $this->lapsed($business);

        $visible = $this->confirmVisible($business, $this->segmentDraft($business));
        $this->assertStringContainsString(
            '2 customers were sent a win-back text in the last 90 days and are skipped.',
            $visible
        );

        $business0 = World::business();
        $this->lapsed($business0);
        $this->lapsed($business0);

        $visible0 = $this->confirmVisible($business0, $this->segmentDraft($business0));
        $this->assertStringNotContainsString('were sent a win-back text in the last 90 days and are skipped.', $visible0);
        $this->assertStringNotContainsString('was sent a win-back text in the last 90 days and is skipped.', $visible0);
    }

    // ---------- S2: banner spacing clause (FQ-02) ----------

    public function test_s2_spacing_banner_clause_is_singular_at_one(): void
    {
        $business = World::business();
        $spacing = $this->member($business);
        $this->touch($business, $spacing, 3);
        $this->member($business);

        $visible = $this->confirmVisible($business, $this->wholeListDraft($business));

        $this->assertStringContainsString(
            '1 customer got a campaign text in the last 7 days and is skipped.',
            $visible
        );
        $this->assertStringNotContainsString(
            '1 customers got a campaign text in the last 7 days and are skipped.',
            $visible
        );
    }

    public function test_s2_spacing_banner_clause_is_plural_at_two_and_absent_at_zero(): void
    {
        $business = World::business();
        foreach (range(1, 2) as $ignored) {
            $spacing = $this->member($business);
            $this->touch($business, $spacing, 3);
        }
        $this->member($business);

        $visible = $this->confirmVisible($business, $this->wholeListDraft($business));
        $this->assertStringContainsString(
            '2 customers got a campaign text in the last 7 days and are skipped.',
            $visible
        );

        $business0 = World::business();
        $this->member($business0);
        $this->member($business0);

        $visible0 = $this->confirmVisible($business0, $this->wholeListDraft($business0));
        $this->assertStringNotContainsString('customers got a campaign text in the last 7 days and are skipped.', $visible0);
        $this->assertStringNotContainsString('customer got a campaign text in the last 7 days and is skipped.', $visible0);
    }

    // ---------- S3: segment scope line ----------

    public function test_s3_segment_scope_line_is_singular_at_one(): void
    {
        $business = World::business();
        $this->lapsed($business);

        $visible = $this->confirmVisible($business, $this->segmentDraft($business));

        $this->assertStringContainsString(
            'This text goes only to past referrers: 1 customer.',
            $visible
        );
        $this->assertStringNotContainsString(
            'This text goes only to past referrers: 1 customers.',
            $visible
        );
    }

    public function test_s3_segment_scope_line_is_plural_at_two_and_absent_at_zero(): void
    {
        $business = World::business();
        $this->lapsed($business);
        $this->lapsed($business);

        $visible = $this->confirmVisible($business, $this->segmentDraft($business));
        $this->assertStringContainsString(
            'This text goes only to past referrers: 2 customers.',
            $visible
        );

        $business0 = World::business();
        $this->member($business0); // consented, never a referrer: empty segment

        $visible0 = $this->confirmVisible($business0, $this->segmentDraft($business0));
        $this->assertStringNotContainsString('This text goes only to past referrers:', $visible0);
    }

    // ---------- S4: zero-sendable cooldown form (confirm L161) ----------

    public function test_s4_zero_sendable_cooldown_line_is_singular_at_one(): void
    {
        $business = World::business();
        $cooled = $this->lapsed($business);
        $this->touch($business, $cooled, 30, winback: true);
        $optedOut = $this->lapsed($business);
        SmsOptOut::create(['phone_e164' => $optedOut->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $visible = $this->confirmVisible($business, $this->segmentDraft($business));

        $this->assertStringContainsString(
            'Total $0.00 for 0 customers. 1 past referrer was sent a win-back text in the last 90 days.',
            $visible
        );
        $this->assertStringNotContainsString(
            'Total $0.00 for 0 customers. 1 past referrers were sent a win-back text in the last 90 days.',
            $visible
        );
    }

    public function test_s4_zero_sendable_cooldown_line_is_plural_at_two_and_absent_at_zero(): void
    {
        $business = World::business();
        foreach ([30, 32] as $daysAgo) {
            $cooled = $this->lapsed($business);
            $this->touch($business, $cooled, $daysAgo, winback: true);
        }
        $optedOut = $this->lapsed($business);
        SmsOptOut::create(['phone_e164' => $optedOut->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $visible = $this->confirmVisible($business, $this->segmentDraft($business));
        $this->assertStringContainsString(
            'Total $0.00 for 0 customers. 2 past referrers were sent a win-back text in the last 90 days.',
            $visible
        );

        $business0 = World::business();
        $optedOut0 = $this->lapsed($business0);
        SmsOptOut::create(['phone_e164' => $optedOut0->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $visible0 = $this->confirmVisible($business0, $this->segmentDraft($business0));
        $this->assertStringNotContainsString('past referrers were sent a win-back text in the last 90 days.', $visible0);
        $this->assertStringNotContainsString('past referrer was sent a win-back text in the last 90 days.', $visible0);
    }
}
