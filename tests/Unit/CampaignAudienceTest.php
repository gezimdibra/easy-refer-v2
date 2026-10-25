<?php

namespace Tests\Unit;

use App\Models\Business;
use App\Models\Membership;
use App\Models\SmsOptOut;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\World;
use Tests\TestCase;

/**
 * The campaign eligibility query (spec Sections 10.3, 5.2;
 * threats G-04, EC-22): one query object feeds the estimate, the
 * confirm screen, and the send, and eligibility is consent
 * PROVENANCE: customer-granted only. An owner-asserted membership
 * is never eligible until the customer's YES reply flips its
 * source to customer_reply.
 */
class CampaignAudienceTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSequence = 7000;

    private function member(Business $business, array $consent): Membership
    {
        $phone = '+1212555'.str_pad((string) $this->phoneSequence++, 4, '0', STR_PAD_LEFT);
        [$membership] = World::membership($business, World::customer($phone));
        $membership->forceFill($consent)->save();

        return $membership->fresh();
    }

    public function test_eligibility_provenance_matrix(): void
    {
        $business = World::business();
        $other = World::business();

        $checkbox = $this->member($business, ['sms_consent' => true, 'consent_source' => 'customer_checkbox']);
        $reply = $this->member($business, ['sms_consent' => true, 'consent_source' => 'customer_reply']);
        $this->member($business, ['sms_consent' => true, 'consent_source' => 'owner_asserted']);
        $this->member($business, ['sms_consent' => false, 'consent_source' => 'customer_checkbox']);
        $optedOut = $this->member($business, ['sms_consent' => true, 'consent_source' => 'customer_checkbox']);
        SmsOptOut::create(['phone_e164' => $optedOut->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);
        $this->member($business, ['sms_consent' => true, 'consent_source' => 'customer_reply', 'removed_at' => now()]);
        $this->member($other, ['sms_consent' => true, 'consent_source' => 'customer_checkbox']);

        $audience = CampaignAudience::forBusiness($business);

        $eligibleIds = collect($audience['memberships'])->pluck('id')->sort()->values()->all();
        $this->assertSame([$checkbox->id, $reply->id], $eligibleIds);
        // Exclusion counts cover this business's non-removed
        // memberships only, one reason each.
        $this->assertSame(1, $audience['exclusions']['owner_asserted_unconfirmed']);
        $this->assertSame(1, $audience['exclusions']['opted_out']);
        $this->assertSame(1, $audience['exclusions']['no_consent']);
    }

    public function test_exclusion_reasons_have_one_precedence_per_member(): void
    {
        $business = World::business();

        // Opted out AND owner-asserted: the opt-out is absolute
        // and is the reason recorded.
        $both = $this->member($business, ['sms_consent' => true, 'consent_source' => 'owner_asserted']);
        SmsOptOut::create(['phone_e164' => $both->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);
        // No consent AND opted out: still the opt-out.
        $noConsentOut = $this->member($business, ['sms_consent' => false, 'consent_source' => 'customer_checkbox']);
        SmsOptOut::create(['phone_e164' => $noConsentOut->customer->phone_e164, 'opted_out_at' => now(), 'source' => 'carrier_stop']);

        $audience = CampaignAudience::forBusiness($business);

        $this->assertSame([], collect($audience['memberships'])->all());
        $this->assertSame(2, $audience['exclusions']['opted_out']);
        $this->assertSame(0, $audience['exclusions']['owner_asserted_unconfirmed']);
        $this->assertSame(0, $audience['exclusions']['no_consent']);
        $this->assertSame(2, array_sum($audience['exclusions']));
    }

    public function test_overlap_of_no_consent_and_owner_asserted_counts_as_no_consent(): void
    {
        $business = World::business();

        // Consent off AND owner-asserted provenance: the design
        // names no precedence for this overlap (FB-02); the
        // code's order is the record, and it counts the member
        // as no_consent, because the consent-off check runs
        // before the provenance check.
        $this->member($business, ['sms_consent' => false, 'consent_source' => 'owner_asserted']);
        // The steady states around the overlap stay as they were.
        $this->member($business, ['sms_consent' => true, 'consent_source' => 'owner_asserted']);
        $this->member($business, ['sms_consent' => false, 'consent_source' => 'customer_checkbox']);

        $audience = CampaignAudience::forBusiness($business);

        $this->assertSame([], collect($audience['memberships'])->all());
        $this->assertSame(0, $audience['exclusions']['opted_out']);
        $this->assertSame(1, $audience['exclusions']['owner_asserted_unconfirmed']);
        $this->assertSame(2, $audience['exclusions']['no_consent']);
    }

    public function test_figures_are_computed_on_the_final_body_for_the_eligible_audience(): void
    {
        $business = World::business();
        $this->member($business, ['sms_consent' => true, 'consent_source' => 'customer_checkbox']);
        $this->member($business, ['sms_consent' => true, 'consent_source' => 'customer_reply']);
        $this->member($business, ['sms_consent' => true, 'consent_source' => 'owner_asserted']);

        $figures = app(CampaignSender::class)->figures($business, 'Spring sale is on');

        $this->assertSame($business->name.': Spring sale is on. Reply STOP to opt out.', $figures['rendered_body']);
        $this->assertSame(1, $figures['segments']);
        $this->assertSame('GSM-7', $figures['encoding']);
        $this->assertSame(2, $figures['per_recipient_cents']);
        $this->assertSame(2, $figures['recipients']);
        $this->assertSame(4, $figures['total_cents']);

        $unicode = app(CampaignSender::class)->figures($business, 'Spring sale 🎉');
        $this->assertSame('Unicode', $unicode['encoding']);
    }
}
