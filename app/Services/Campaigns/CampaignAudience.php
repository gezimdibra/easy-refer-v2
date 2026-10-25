<?php

namespace App\Services\Campaigns;

use App\Models\Business;
use App\Models\Membership;
use App\Models\SmsOptOut;
use Illuminate\Support\Collection;

/**
 * The campaign eligibility query (spec Sections 10.3, 5.2): ONE
 * object used by the estimate endpoint, the confirm screen, and
 * the send, so the three numbers can never disagree about the
 * rule. A membership is eligible iff it is not removed, its
 * consent is on, its consent provenance is customer-granted
 * (customer_checkbox or customer_reply; owner_asserted is NEVER
 * eligible on its own, threat G-04), and its number is not on
 * the platform opt-out list (EC-22: any business's STOP stops
 * everyone's campaigns).
 */
final class CampaignAudience
{
    /** Consent sources a customer granted themselves. */
    public const CUSTOMER_SOURCES = ['customer_checkbox', 'customer_reply'];

    /**
     * @return array{
     *     memberships: Collection<int, Membership>,
     *     exclusions: array{owner_asserted_unconfirmed: int, opted_out: int, no_consent: int}
     * }
     */
    public static function forBusiness(Business $business): array
    {
        $memberships = Membership::query()
            ->where('business_id', $business->id)
            ->whereNull('removed_at')
            ->with('customer')
            ->orderBy('id')
            ->get();

        $optedOutPhones = SmsOptOut::query()
            ->whereIn('phone_e164', $memberships->map(fn (Membership $m) => $m->customer->phone_e164))
            ->pluck('phone_e164')
            ->flip();

        $eligible = collect();
        $exclusions = [
            'owner_asserted_unconfirmed' => 0,
            'opted_out' => 0,
            'no_consent' => 0,
        ];

        foreach ($memberships as $membership) {
            $phone = $membership->customer->phone_e164;

            // One reason per member, in precedence order: the
            // opt-out is absolute, then consent simply off,
            // then unconfirmed provenance. A member with consent
            // off AND owner-asserted provenance therefore counts
            // as no_consent (pinned by CampaignAudienceTest,
            // FB-02: the design names no precedence for this
            // overlap, so the code's order is the record).
            if ($optedOutPhones->has($phone)) {
                $exclusions['opted_out']++;

                continue;
            }

            if (! $membership->sms_consent) {
                $exclusions['no_consent']++;

                continue;
            }

            if (! in_array($membership->consent_source, self::CUSTOMER_SOURCES, true)) {
                $exclusions['owner_asserted_unconfirmed']++;

                continue;
            }

            $eligible->push($membership);
        }

        return ['memberships' => $eligible, 'exclusions' => $exclusions];
    }
}
