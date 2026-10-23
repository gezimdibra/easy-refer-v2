<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\Reward;
use App\Services\Billing\VisitValuation;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The owner Overview (spec Section 5 and the design amendment
 * Section 6.4.1): time first (today against yesterday), then money
 * (the Value panel), then setup (the checklist). With zero
 * referrals the screen is the retention surface: one dominant
 * action (print the QR code), the starter pack while rewards hold
 * defaults, honestly-zero tiles, and the standing counters stay
 * hidden until the first enrollment exists.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, VisitValuation $valuation): View
    {
        $user = $request->user();
        $business = $user->business;
        $timezone = new \DateTimeZone($business->timezone ?: 'America/New_York');
        $utc = new \DateTimeZone('UTC');

        $todayStart = now()->setTimezone($timezone)->startOfDay()->setTimezone($utc);
        $yesterdayStart = $todayStart->copy()->subDay();
        $todayEnd = $todayStart->copy()->addDay();

        $redeemedBetween = fn ($from, $to) => Referral::query()
            ->where('business_id', $business->id)
            ->where('state', Referral::STATE_REDEEMED)
            ->whereBetween('redeemed_at', [$from, $to])
            ->count();

        $hasEnrollments = $business->memberships()->exists();
        $hasRedemptions = $business->referrals()->where('state', Referral::STATE_REDEEMED)->exists();

        $allTime = $valuation->settle($business);

        return view('owner.dashboard', [
            'user' => $user,
            'business' => $business,
            'emailVerified' => $user->hasVerifiedEmail(),
            'hasEnrollments' => $hasEnrollments,
            'hasRedemptions' => $hasRedemptions,
            // The designed tile set (design 3.4): two today tiles,
            // then the standing counters. Scans are the scan events
            // enrollment writes (spec 5.1 "scans (enrollments)").
            'tiles' => [
                'scans_today' => $business->referralEvents()
                    ->where('event_type', 'scan')
                    ->whereBetween('created_at', [$todayStart, $todayEnd])
                    ->count(),
                'referrals_today' => $redeemedBetween($todayStart, $todayEnd),
                'referrals_yesterday' => $redeemedBetween($yesterdayStart, $todayStart),
                'rewards_waiting' => Reward::query()
                    ->where('business_id', $business->id)
                    ->where('state', Reward::STATE_AVAILABLE)
                    ->count(),
                'review_waiting' => Referral::query()
                    ->where('business_id', $business->id)
                    ->where('hold', true)
                    ->count()
                    + Reward::query()
                        ->where('business_id', $business->id)
                        ->where('hold', true)
                        ->count(),
            ],
            'value' => $valuation->forMonth($business, now()),
            'valueAllTime' => $allTime,
            'monthLabel' => now()->setTimezone($timezone)->format('F'),
            // The starter pack card (PACK-00) shows only while the
            // reward fields still hold platform defaults, which in
            // data terms means the friend reward was never set.
            'showStarterPack' => $business->friend_reward_text === null,
            'packs' => \App\Support\StarterPacks::forType($business->business_type),
            // The onboarding checklist is exactly AUTH-SEC-03's
            // four items. Card capture has no surface until the
            // Section 5.5 Settings and billing work lands, so the
            // card item honestly reads incomplete until then.
            'checklist' => [
                'email_verified' => $user->hasVerifiedEmail(),
                'reward_text' => $business->friend_reward_text !== null,
                'poster_downloaded' => $business->referralEvents()->where('event_type', 'poster_downloaded')->exists(),
                'card_on_file' => false,
            ],
        ]);
    }
}
