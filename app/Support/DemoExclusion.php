<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;

/**
 * The demo double exclusion, in one place (A-18 two-key
 * Finding 3): a demo row is excluded by BOTH independent legs,
 * the business flag (businesses.is_demo) AND the row stamp
 * (referrals.source = 'demo', billing_events.coverage = 'demo').
 * A surface that filters only one leg miscounts a stamp
 * mismatch row: a referral stamped source='referral' on the
 * demo business, or a referral stamped source='demo' on a real
 * business, must count on NO platform, money, forecast or
 * owner surface. Every such surface routes its query through
 * here so it cannot forget a leg.
 *
 * Owner surfaces scoped to the authenticated owner's own
 * business carry the flag leg by that scoping itself (the demo
 * business has no owner account, so no owner query can select
 * it) and apply the stamp leg from here on top.
 */
final class DemoExclusion
{
    /** Whether this business is excluded from every real surface. */
    public static function businessIsDemo(Business $business): bool
    {
        return $business->isDemo();
    }

    /**
     * Both legs on a Referral query: source stamp is not demo
     * AND the owning business is not flagged demo.
     */
    public static function referrals(Builder $query): Builder
    {
        return $query
            ->where('source', '!=', 'demo')
            ->whereHas('business', fn (Builder $business) => $business->where('is_demo', false));
    }

    /**
     * Both legs on a BillingEvent query: coverage stamp is not
     * demo AND the owning business is not flagged demo.
     */
    public static function billingEvents(Builder $query): Builder
    {
        return $query
            ->where('coverage', '!=', 'demo')
            ->whereHas('business', fn (Builder $business) => $business->where('is_demo', false));
    }

    /**
     * Both legs on a Reward query: the owning business is not
     * flagged demo AND the reward's referral, when it has one,
     * is not stamped demo. (Rewards carry no stamp of their
     * own; their referral stamp is the second leg.)
     */
    public static function rewards(Builder $query): Builder
    {
        return $query
            ->whereHas('business', fn (Builder $business) => $business->where('is_demo', false))
            ->whereDoesntHave('referral', fn (Builder $referral) => $referral->where('source', 'demo'));
    }

    /**
     * Both legs on a ReferralEvent query: the owning business
     * is not flagged demo AND the event's referral, when it has
     * one, is not stamped demo.
     */
    public static function referralEvents(Builder $query): Builder
    {
        return $query
            ->whereHas('business', fn (Builder $business) => $business->where('is_demo', false))
            ->whereDoesntHave('referral', fn (Builder $referral) => $referral->where('source', 'demo'));
    }
}
