<?php

namespace App\Services\Owner;

use App\Models\ActionCenterDismissal;
use App\Models\BillingRecord;
use App\Models\Business;
use App\Models\PaymentMethod;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\SmsCampaign;
use App\Models\SmsCampaignRecipient;
use App\Models\User;
use App\Support\AgeLabel;
use App\Support\SetupChecklist;

/**
 * The Owner Action Center's read model (RK-01, Train 2; copy
 * Amendment 22): every state that is waiting on the owner,
 * aggregated into one inbox. Items are computed from live
 * rows on every read, never stored, so resolving a state
 * removes its row by construction. The only stored state is
 * the dismissal table: an item is hidden while the live
 * state's fingerprint still matches the dismissed one, and
 * resurfaces the moment the state changes.
 *
 * One row per open state, sorted by urgency (lowest first):
 * money stopped, money about to stop, customers waiting,
 * then setup. Source keys are stable strings: they appear in
 * the dismiss route and in the dismissal table.
 *
 * @phpstan-type Item array{key: string, urgency: int, line: string, action_label: string, url: string, fingerprint: string}
 */
class ActionCenter
{
    /** A review hold older than this is its own, staler state. */
    public const HELD_TOO_LONG_DAYS = 7;

    /**
     * The open items for a business, dismissals applied. A
     * caller that already computed this request's setup
     * checklist (the dashboard, for its panel) passes it in,
     * so the checklist's queries run once per page.
     *
     * @return list<Item>
     */
    public function items(Business $business, User $user, ?array $checklist = null): array
    {
        $dismissals = ActionCenterDismissal::query()
            ->where('business_id', $business->id)
            ->pluck('state_fingerprint', 'item_key');

        return collect($this->sources($business, $user, $checklist))
            ->filter(fn (?array $item) => $item !== null)
            ->reject(fn (array $item) => ($dismissals[$item['key']] ?? null) === $item['fingerprint'])
            ->sortBy([['urgency', 'asc'], ['key', 'asc']])
            ->values()
            ->all();
    }

    /**
     * The dismissal handles of the items currently open, in
     * display order. The dismiss form and its route carry a
     * positional handle, never the item key itself: keys mirror
     * internal state names (one of them is the raw billing
     * status word), and the raw status word never renders
     * user-facing (SV-03). The controller resolves a posted
     * handle back through this list.
     *
     * @return list<int>
     */
    public function openHandles(Business $business, User $user): array
    {
        return array_keys($this->items($business, $user));
    }

    public function openCount(Business $business, User $user, ?array $checklist = null): int
    {
        return count($this->items($business, $user, $checklist));
    }

    /**
     * Dismiss one item at its current fingerprint. The handle
     * is either the item's position in the open list (what the
     * rendered form posts; see openHandles) or the source key
     * itself. Returns false when the handle names nothing
     * known, so the route can answer 404 for invented handles.
     */
    public function dismiss(Business $business, User $user, int|string $handle): bool
    {
        if (is_numeric($handle)) {
            $item = $this->items($business, $user)[(int) $handle] ?? null;

            if ($item === null) {
                return false;
            }

            $key = $item['key'];
        } else {
            if (! in_array($handle, self::sourceKeys(), true)) {
                return false;
            }

            $key = $handle;
        }

        foreach ($this->sources($business, $user) as $item) {
            if ($item !== null && $item['key'] === $key) {
                ActionCenterDismissal::query()->updateOrCreate(
                    ['business_id' => $business->id, 'item_key' => $key],
                    ['state_fingerprint' => $item['fingerprint']],
                );

                return true;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public static function sourceKeys(): array
    {
        return [
            'past_due',
            'card_expired',
            'card_expires_before_charge',
            'reward_held_too_long',
            'review_holds',
            'failed_deliveries',
            'review_link_missing',
            'setup_checklist',
        ];
    }

    /**
     * @return list<Item|null>
     */
    private function sources(Business $business, User $user, ?array $checklist = null): array
    {
        // One card lookup serves both card states.
        $card = PaymentMethod::defaultFor($business);

        return [
            $this->pastDue($business),
            $this->cardExpired($business, $card),
            $this->cardExpiresBeforeCharge($business, $card),
            $this->rewardHeldTooLong($business),
            $this->reviewHolds($business),
            $this->failedDeliveries($business),
            $this->reviewLinkMissing($business),
            $this->setupChecklist($business, $user, $checklist),
        ];
    }

    /**
     * @return Item|null
     */
    private function pastDue(Business $business): ?array
    {
        if ($business->billing_status !== 'past_due') {
            return null;
        }

        // A fresh failure is a changed state: the fingerprint
        // rides on the newest failed billing record, so a
        // dismissal made during one failure does not hide the
        // next one.
        $latestFailure = BillingRecord::query()
            ->where('business_id', $business->id)
            ->where('state', 'failed')
            ->max('id');

        return [
            'key' => 'past_due',
            'urgency' => 10,
            'line' => \App\Support\BillingCopy::pastDueLine(),
            'action_label' => 'Update card',
            'url' => route('settings.billing').'#card',
            'fingerprint' => 'past_due|'.($latestFailure ?? 0),
        ];
    }

    /**
     * @return Item|null
     */
    private function cardExpired(Business $business, ?PaymentMethod $card): ?array
    {
        if ($card === null || ! self::cardIsExpired($card)) {
            return null;
        }

        return [
            'key' => 'card_expired',
            'urgency' => 20,
            'line' => 'Your card on file has expired. Replace it to keep monthly charges going.',
            'action_label' => 'Replace card',
            'url' => route('settings.billing').'#card',
            'fingerprint' => 'card|'.$card->id,
        ];
    }

    /**
     * The expiry guard (RK-03; AC-08): the card on file is
     * still valid today but its expiry month ends before the
     * next charge date, so the next charge would hit an expired
     * card. Distinct from AC-05 (already expired) and from
     * SV-02's separate 60-day billing-page pill: this row is
     * keyed to the charge date, not a day count. The reminder
     * email (BillingForecast) uses this same predicate through
     * cardExpiresBeforeNextCharge(), so the row and the email
     * can never disagree about whether the state holds.
     *
     * @return Item|null
     */
    private function cardExpiresBeforeCharge(Business $business, ?PaymentMethod $card): ?array
    {
        if ($card === null || ! self::cardExpiresBeforeNextCharge($card)) {
            return null;
        }

        return [
            'key' => 'card_expires_before_charge',
            'urgency' => 25,
            'line' => 'Your card expires before your next charge on '
                .\App\Support\BillingForecast::nextChargeDate()->format('F j')
                .'. Replace it so the charge goes through.',
            'action_label' => 'Replace card',
            'url' => route('settings.billing').'#card',
            'fingerprint' => 'card_expires|'.$card->id,
        ];
    }

    /**
     * The RK-03 expiry-guard predicate: not yet expired (an
     * expired card is AC-05's state), and the expiry month
     * ends before the next charge date.
     */
    public static function cardExpiresBeforeNextCharge(PaymentMethod $card): bool
    {
        if (self::cardIsExpired($card)) {
            return false;
        }

        $expiryMonthEnd = \Illuminate\Support\Carbon::create($card->exp_year, $card->exp_month, 1)->endOfMonth();

        return $expiryMonthEnd->lt(\App\Support\BillingForecast::nextChargeDate());
    }

    /**
     * The review-link row (LV-02; AC-09): the business has at
     * least one redeemed referral, so customers are visiting,
     * but no Google review link is set for them to leave a
     * review with. Before the first redemption the row stays
     * silent (the checklist step carries it until then).
     *
     * @return Item|null
     */
    private function reviewLinkMissing(Business $business): ?array
    {
        if ($business->google_review_url !== null && trim((string) $business->google_review_url) !== '') {
            return null;
        }

        $hasRedemption = \App\Models\Referral::query()
            ->where('business_id', $business->id)
            ->where('state', \App\Models\Referral::STATE_REDEEMED)
            ->exists();

        if (! $hasRedemption) {
            return null;
        }

        return [
            'key' => 'review_link_missing',
            'urgency' => 60,
            'line' => 'Add your Google review link so customers can leave a review after they visit.',
            'action_label' => 'Add review link',
            'url' => route('settings.reviews'),
            'fingerprint' => 'review_link|missing',
        ];
    }

    /**
     * The billing page's own expiry rule (SettingsController):
     * a card is expired once its expiry month has passed.
     */
    public static function cardIsExpired(PaymentMethod $card): bool
    {
        return $card->exp_year < now()->year
            || ($card->exp_year === now()->year && $card->exp_month < now()->month);
    }

    /**
     * @return Item|null
     */
    private function rewardHeldTooLong(Business $business): ?array
    {
        $reward = Reward::query()
            ->where('business_id', $business->id)
            ->where('hold', true)
            ->whereNotNull('held_at')
            ->where('held_at', '<=', now()->subDays(self::HELD_TOO_LONG_DAYS))
            ->oldest('held_at')
            ->first();

        if ($reward === null) {
            return null;
        }

        return [
            'key' => 'reward_held_too_long',
            'urgency' => 30,
            'line' => 'A reward has been waiting '.AgeLabel::of($reward->held_at).' for review.',
            'action_label' => 'Open review',
            'url' => route('reviews'),
            // The age growing is not a state change; the item
            // is the reward itself, so the fingerprint is too.
            'fingerprint' => 'reward|'.$reward->id,
        ];
    }

    /**
     * @return Item|null
     */
    private function reviewHolds(Business $business): ?array
    {
        $referralIds = Referral::query()
            ->where('business_id', $business->id)
            ->where('hold', true)
            ->pluck('id');
        $rewardIds = Reward::query()
            ->where('business_id', $business->id)
            ->where('hold', true)
            ->pluck('id');

        $count = $referralIds->count() + $rewardIds->count();

        if ($count === 0) {
            return null;
        }

        return [
            'key' => 'review_holds',
            'urgency' => 40,
            'line' => $count === 1
                ? '1 item is waiting for your review.'
                : $count.' items are waiting for your review.',
            'action_label' => 'Open review',
            'url' => route('reviews'),
            'fingerprint' => 'holds|'.md5($referralIds->sort()->implode(',').';'.$rewardIds->sort()->implode(',')),
        ];
    }

    /**
     * @return Item|null
     */
    private function failedDeliveries(Business $business): ?array
    {
        $campaign = SmsCampaign::query()
            ->where('business_id', $business->id)
            ->whereIn('status', [SmsCampaign::STATUS_SENT, SmsCampaign::STATUS_FAILED])
            ->whereHas('recipients', fn ($query) => $query->whereIn('delivery_state', [
                SmsCampaignRecipient::STATE_FAILED,
                SmsCampaignRecipient::STATE_UNDELIVERED,
            ]))
            ->latest('id')
            ->first();

        if ($campaign === null) {
            return null;
        }

        $count = $campaign->recipients()
            ->whereIn('delivery_state', [
                SmsCampaignRecipient::STATE_FAILED,
                SmsCampaignRecipient::STATE_UNDELIVERED,
            ])
            ->count();

        return [
            'key' => 'failed_deliveries',
            'urgency' => 50,
            'line' => $count === 1
                ? '1 text could not be delivered in a recent campaign.'
                : $count.' texts could not be delivered in a recent campaign.',
            'action_label' => 'View campaign',
            'url' => route('campaigns.show', $campaign),
            'fingerprint' => 'campaign|'.$campaign->id.'|'.$count,
        ];
    }

    /**
     * @return Item|null
     */
    private function setupChecklist(Business $business, User $user, ?array $checklist = null): ?array
    {
        $checklist ??= SetupChecklist::for($business, $user);
        [$done, $total] = SetupChecklist::progress($checklist);

        if ($done >= $total) {
            return null;
        }

        return [
            'key' => 'setup_checklist',
            'urgency' => 70,
            'line' => 'Your setup is not finished: '.$done.' of '.$total.' steps done.',
            'action_label' => 'Finish setup',
            'url' => route('dashboard'),
            'fingerprint' => 'checklist|'.$done.'/'.$total,
        ];
    }

}
