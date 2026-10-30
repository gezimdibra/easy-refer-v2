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
use App\Support\Onboarding;
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
 * `dismissible` (coherence Phase 2, G3): a row whose state
 * blocks the business's money or service — the past-due row —
 * is not dismissible while the state holds. Dismissal is for
 * awareness rows; a blocking truth stays on the page until
 * the state itself changes.
 *
 * @phpstan-type Item array{key: string, urgency: int, line: string, action_label: string, url: string, fingerprint: string, dismissible: bool}
 */
class ActionCenter
{
    /** A review hold older than this earns the review row's stale clause (G5). */
    public const HELD_TOO_LONG_DAYS = 7;

    /**
     * The open items for a business, dismissals applied. A
     * caller that already computed this request's setup
     * checklist (the dashboard, for its panel) passes it in,
     * so the checklist's queries run once per page.
     *
     * B7: a caller that already fetched this request's held
     * collections, dismissal map, or redeemed-referral fact
     * (the dashboard) hands them over in $shared, and the
     * sources below read from the hand-over instead of
     * re-querying the same rows. Every key is optional; a
     * caller that supplies none gets exactly the standalone
     * behavior (the Action Center page supplies none).
     *
     * @param  array{held_referrals?: \Illuminate\Support\Collection<int, Referral>, held_rewards?: \Illuminate\Support\Collection<int, Reward>, dismissals?: \Illuminate\Support\Collection<string, string>, has_redeemed_referral?: bool}|null  $shared
     * @return list<Item>
     */
    public function items(Business $business, User $user, ?array $checklist = null, ?array $shared = null): array
    {
        return $this->visibleItems(
            $this->sources($business, $user, $checklist, $shared),
            $business,
            $shared['dismissals'] ?? null,
        );
    }

    /**
     * The page state behind the rows (G3, coordinator ruling
     * Q3): `quiet` is true when at least one source is open
     * but every visible row stands dismissed. In that state
     * the page carries the honest quiet line (AC-10) — never
     * the "good standing" claim (AC-02), which renders only
     * when no source is open at all.
     *
     * @return array{items: list<Item>, quiet: bool}
     */
    public function pageState(Business $business, User $user): array
    {
        // ID-01/ID-03 (Amendment 46): a prompt's `shown` event
        // is logged only when the owner actually renders the
        // Action Center page (this entry point) — never from
        // badge/count reads, which share sources() but must
        // not manufacture "shown" weeks for the suppression
        // counters.
        $this->recordPromptShown = true;
        try {
            $sources = $this->sources($business, $user);
        } finally {
            $this->recordPromptShown = false;
        }
        $items = $this->visibleItems($sources, $business);

        return [
            'items' => $items,
            'quiet' => collect($sources)->filter()->isNotEmpty() && $items === [],
        ];
    }

    /** Whether this read is the Action Center page itself (prompt shown logging). */
    private bool $recordPromptShown = false;

    /**
     * @param  list<Item|null>  $sources
     * @param  \Illuminate\Support\Collection<string, string>|null  $dismissals  the dismissal map when the caller already plucked it this request (B7 M-5)
     * @return list<Item>
     */
    private function visibleItems(array $sources, Business $business, ?\Illuminate\Support\Collection $dismissals = null): array
    {
        $dismissals ??= ActionCenterDismissal::query()
            ->where('business_id', $business->id)
            ->pluck('state_fingerprint', 'item_key');

        return collect($sources)
            ->filter(fn (?array $item) => $item !== null)
            // G3: a non-dismissible row is never filtered out,
            // whatever the dismissal table holds.
            ->reject(fn (array $item) => $item['dismissible']
                && ($dismissals[$item['key']] ?? null) === $item['fingerprint'])
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

    /**
     * @param  array{held_referrals?: \Illuminate\Support\Collection<int, Referral>, held_rewards?: \Illuminate\Support\Collection<int, Reward>, dismissals?: \Illuminate\Support\Collection<string, string>, has_redeemed_referral?: bool}|null  $shared
     */
    public function openCount(Business $business, User $user, ?array $checklist = null, ?array $shared = null): int
    {
        return count($this->items($business, $user, $checklist, $shared));
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
                if (! $item['dismissible']) {
                    // G3: a blocking row cannot be dismissed
                    // away; the endpoint answers as if the row
                    // were not dismissible at all (404).
                    return false;
                }

                ActionCenterDismissal::query()->updateOrCreate(
                    ['business_id' => $business->id, 'item_key' => $key],
                    ['state_fingerprint' => $item['fingerprint']],
                );

                // Idea Train 1: a prompt dismissal also feeds
                // the shared prompt event log, where the
                // per-item suppression policies count it.
                if (in_array($key, [\App\Services\Campaigns\CampaignPrompts::ITEM_SLOW_DAY, \App\Services\Campaigns\CampaignPrompts::ITEM_WINBACK, \App\Services\Campaigns\CampaignPrompts::ITEM_TOP_REFERRER], true)) {
                    \App\Services\Campaigns\CampaignPrompts::recordDismissed($business, $key);
                }

                // ID-06 (Amendment 49): dismissal is TERMINAL
                // for the second-placement prompt (design
                // Section 5.4 — a deliberate divergence from
                // this table's fingerprint resurfacing), so
                // the prompt-state record stamps DISMISSED in
                // addition to the standard dismissal row above.
                if ($key === SecondPlacement::ITEM_KEY) {
                    app(SecondPlacement::class)->stampDismissed($business);
                }

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
            'no_card_on_file',
            'review_holds',
            'rewards_expiring',
            'failed_deliveries',
            'review_link_missing',
            'setup_checklist',
            'slow_day_boost',
            'lapsed_referrer_winback',
            'top_referrer_recognition',
            'second_placement',
        ];
    }

    /**
     * @param  array{held_referrals?: \Illuminate\Support\Collection<int, Referral>, held_rewards?: \Illuminate\Support\Collection<int, Reward>, dismissals?: \Illuminate\Support\Collection<string, string>, has_redeemed_referral?: bool}|null  $shared
     * @return list<Item|null>
     */
    private function sources(Business $business, User $user, ?array $checklist = null, ?array $shared = null): array
    {
        // One card lookup serves both card states. B7 M-6:
        // when the caller supplied this request's checklist and
        // its card_on_file step is false, that bool IS the
        // checklist's own defaultFor() result (SetupChecklist,
        // coherence G2), so the lookup's answer is already
        // known to be null and the duplicate fetch is skipped.
        // When a card exists this code still needs the row
        // itself (the expiry fingerprints), so the fetch runs.
        $card = ($checklist !== null && ($checklist['card_on_file'] ?? true) === false)
            ? null
            : PaymentMethod::defaultFor($business);

        // G4: the no-chargeable-card row owns the removed-card
        // state; while it fires, the setup row stands down for
        // that state (one state, one row).
        $noCard = $this->noCardOnFile($business, $user, $card, $checklist);

        return [
            $this->pastDue($business),
            $this->cardExpired($business, $card),
            $this->cardExpiresBeforeCharge($business, $card),
            $noCard,
            $this->reviewHolds($business, $shared),
            $this->expiringRewards($business),
            $this->failedDeliveries($business),
            $this->reviewLinkMissing($business, $shared['has_redeemed_referral'] ?? null),
            $this->setupChecklist($business, $user, $checklist, $noCard !== null),
            $this->slowDayBoost($business),
            $this->lapsedReferrerWinback($business),
            $this->topReferrerRecognition($business),
            $this->secondPlacement($business),
        ];
    }

    /**
     * ID-06 (Amendment 49): the second-code prompt at the
     * first SETTLED visit — one row, urgency 82 in the growth
     * tier (below the same-day slow-day boost at 80, above
     * monthly-recurring recognition at 85 in its train), once
     * per business ever, sending nothing. The whole read lives
     * in SecondPlacement (the settled predicate, the placed
     * set, the lifecycle); this source only adapts it to the
     * row shape and passes the page-read flag through, so
     * stamps land only when the Action Center page itself
     * renders (the shipped shown-stamp discipline).
     *
     * @return Item|null
     */
    private function secondPlacement(Business $business): ?array
    {
        return app(SecondPlacement::class)->evaluate($business, $this->recordPromptShown);
    }

    /**
     * The growth prompts' shared gate helper (ID-01 G2/G3/G4 =
     * ID-03 P2/P3, one read so the two prompts can never
     * disagree about whether the business may be prompted):
     * billing status ACTIVE (past-due never prompts, even in
     * grace), the full flowIsActive picture, and the marketing
     * gateway available.
     */
    private function promptGatesPass(Business $business): bool
    {
        if ($business->billing_status !== 'active') {
            return false;
        }

        if (! $business->flowIsActive()) {
            return false;
        }

        return ! app(\App\Contracts\MarketingSmsGateway::class) instanceof \App\Services\Twilio\NullMarketingSmsGateway;
    }

    /**
     * ID-01 Phase 1 (Amendment 46): the OWNER-DECLARED quiet
     * day prompt. The owner tap (the CAMP-18 preset in the
     * composer, any day) is the feature's primary shape; this
     * row is a subordinate reminder for the day the OWNER
     * picked — its copy says "the quiet day you picked" and
     * never claims the day is slow by any measure. No system
     * detection exists in this build: the dormant analyzer's
     * output is never read here. Gates G1-G8 per the design;
     * suppression per CampaignPrompts' slow-day policy.
     *
     * @return Item|null
     */
    private function slowDayBoost(Business $business): ?array
    {
        // G1 candidate today — the cheap legs first, so a
        // business with no pick costs this read zero queries.
        if ($business->slow_day_weekday === null || $business->slow_day_reminders_off) {
            return null;
        }

        $local = $business->localTime(now());
        if ((int) $local->dayOfWeek !== (int) $business->slow_day_weekday) {
            return null;
        }

        // G2 + G3 + G4.
        if (! $this->promptGatesPass($business)) {
            return null;
        }

        // G6 spacing: no campaign sent in the previous 7 days
        // and none today (a boundary touch exactly 7x24h old
        // has cleared).
        $sentRecently = SmsCampaign::query()
            ->where('business_id', $business->id)
            ->whereIn('status', [SmsCampaign::STATUS_SENT, SmsCampaign::STATUS_SENDING])
            ->where('sent_at', '>', now()->subDays((int) config('campaigns.slow_day_prompt_spacing_days', 7)))
            ->exists();
        if ($sentRecently) {
            return null;
        }

        // G7 suppression.
        if (\App\Services\Campaigns\CampaignPrompts::slowDaySilenced($business)) {
            return null;
        }

        // G5 audience: at least the floor sendable, and today's
        // remaining daily cap can carry that many.
        $sender = app(\App\Services\Campaigns\CampaignSender::class);
        $audienceCount = \App\Services\Campaigns\CampaignAudience::forBusiness($business)['memberships']->count();
        $floor = (int) config('campaigns.prompt_min_sendable', 5);
        $fits = max(0, min($audienceCount, (int) config('campaigns.daily_cap') - $sender->usedToday($business)));
        if ($audienceCount < $floor || $fits < $floor) {
            return null;
        }

        $weekday = $local->format('l');
        $line = $weekday.' is the quiet day you picked. A ready-made text for your customers is ready to review.';

        // SD-05 results clause: only with a closed 7-day
        // window (the last boost send is more than 7 full days
        // old), in CampaignResults' own definitions, with the
        // honesty sentence attached.
        $lastBoost = SmsCampaign::query()
            ->where('business_id', $business->id)
            ->whereIn('status', [SmsCampaign::STATUS_SENT, SmsCampaign::STATUS_SENDING])
            ->where('origin', 'like', 'slow_day%')
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->first();

        if ($lastBoost !== null && $lastBoost->sent_at->copy()->addDays(7)->isPast()) {
            $results = \App\Services\Campaigns\CampaignResults::forCampaign($lastBoost);
            $line .= ' Last time: '.$results['delivered'].' texts delivered, and '.$results['visited_7']
                .' customers visited within 7 days of the text. That counts visits after the text; it does not prove the text caused them.';
        }

        if ($this->recordPromptShown) {
            \App\Services\Campaigns\CampaignPrompts::recordShown($business, \App\Services\Campaigns\CampaignPrompts::ITEM_SLOW_DAY);
        }

        return [
            'key' => 'slow_day_boost',
            'urgency' => 80,
            'line' => $line,
            'action_label' => 'Start the text',
            'url' => route('campaigns.create'),
            'post_url' => route('campaigns.preset'),
            'post_fields' => ['preset' => 'slow_day', 'origin' => 'slow_day_prompt'],
            'fingerprint' => 'slow_day|'.$business->slow_day_weekday.'|pick|'.$local->format('o-\WW'),
            'dismissible' => true,
        ];
    }

    /**
     * ID-03 (Amendment 46): the win-back prompt. A standing
     * condition, announced once it is worth announcing: the
     * SENDABLE segment (post-consent, post-STOP, post-F1,
     * post-cooldown — the same read the send honours) is at
     * least 5. Gates P1-P6 per the design; suppression per
     * CampaignPrompts' win-back policy (harder than ID-01's,
     * because a standing truth repeated weekly is the fatigue
     * pattern).
     *
     * @return Item|null
     */
    private function lapsedReferrerWinback(Business $business): ?array
    {
        // P2 + P3 first (cheap): past-due never prompts.
        if (! $this->promptGatesPass($business)) {
            return null;
        }

        // P4: no win-back send in the previous 30 days.
        $recentWinback = SmsCampaign::query()
            ->where('business_id', $business->id)
            ->where('audience_segment', SmsCampaign::SEGMENT_LAPSED_REFERRERS)
            ->whereIn('status', [SmsCampaign::STATUS_SENT, SmsCampaign::STATUS_SENDING])
            ->where('sent_at', '>', now()->subDays((int) config('campaigns.winback_prompt_silence_days', 30)))
            ->exists();
        if ($recentWinback) {
            return null;
        }

        // P6: the owner acted on a slow-day prompt inside the
        // last 7 days — a second converting growth ask stands
        // down that week.
        if (\App\Services\Campaigns\CampaignPrompts::actedOnSlowDayWithin($business, 7)) {
            return null;
        }

        // P1: the sendable count, from the one audience read.
        $audience = \App\Services\Campaigns\CampaignAudience::forBusiness($business, SmsCampaign::SEGMENT_LAPSED_REFERRERS);
        $sendable = $audience['memberships']->count();
        if ($sendable < (int) config('campaigns.prompt_min_sendable', 5)) {
            return null;
        }

        // P5 suppression.
        if (\App\Services\Campaigns\CampaignPrompts::winbackSilenced($business)) {
            return null;
        }

        $line = $sendable.' past referrers have gone quiet. A ready-made win-back text is ready to review.';

        // WB-11 results clause: a win-back campaign whose
        // 14-day window has fully closed. {shared} counts
        // attempted recipients with a NEW referral created
        // inside the window — the behaviour the text asked
        // for — never a broader visited count.
        $lastWinback = SmsCampaign::query()
            ->where('business_id', $business->id)
            ->where('audience_segment', SmsCampaign::SEGMENT_LAPSED_REFERRERS)
            ->whereIn('status', [SmsCampaign::STATUS_SENT, SmsCampaign::STATUS_SENDING])
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->first();

        if ($lastWinback !== null && $lastWinback->sent_at->copy()->addDays(14)->isPast()) {
            $results = \App\Services\Campaigns\CampaignResults::forCampaign($lastWinback);
            $attemptedIds = $lastWinback->recipients()
                ->whereIn('delivery_state', [
                    SmsCampaignRecipient::STATE_QUEUED,
                    SmsCampaignRecipient::STATE_SENT,
                    SmsCampaignRecipient::STATE_DELIVERED,
                ])
                ->pluck('membership_id')
                ->unique()
                ->all();
            $shared = $attemptedIds === [] ? 0 : \App\Support\DemoExclusion::referrals(Referral::query())
                ->whereIn('referrer_membership_id', $attemptedIds)
                ->where('created_at', '>', $lastWinback->sent_at)
                ->where('created_at', '<=', $lastWinback->sent_at->copy()->addDays(14))
                ->distinct()
                ->count('referrer_membership_id');
            $line .= ' Last win-back: '.$results['delivered'].' texts delivered, and '.$shared
                .' past referrers sent a new referral within 14 days of the text. That counts referrals after the text; it does not prove the text caused them.';
        }

        if ($this->recordPromptShown) {
            \App\Services\Campaigns\CampaignPrompts::recordShown($business, \App\Services\Campaigns\CampaignPrompts::ITEM_WINBACK);
        }

        $local = $business->localTime(now());

        return [
            'key' => 'lapsed_referrer_winback',
            'urgency' => 80,
            'line' => $line,
            'action_label' => 'Start the text',
            'url' => route('campaigns.create'),
            'post_url' => route('campaigns.preset'),
            'post_fields' => ['preset' => 'win_back_referrers', 'origin' => 'winback_prompt'],
            'fingerprint' => 'winback|'.intdiv($sendable, 5).'|'.$local->format('o-\WW'),
            'dismissible' => true,
        ];
    }

    /**
     * ID-02 (Amendment 48): the monthly top-referrer
     * recognition card. Gates per the design's section 5.2:
     * the window is open, the standings yield a sendable
     * leader (or co-top set) at or above the floor, the shared
     * prompt gates pass (billing ACTIVE — a past-due business
     * gets no card even inside grace, and a paused one fails
     * flowIsActive anyway — plus the full flow picture, which
     * covers closed, disabled, textless, and no-card states),
     * and at least one leader has not already been sent this
     * month's text. Standings compute live on every read, so a
     * business that recovers inside the window re-renders the
     * card with no cleanup anywhere.
     *
     * @return Item|null
     */
    private function topReferrerRecognition(Business $business): ?array
    {
        $month = \App\Services\Campaigns\TopReferrerStandings::currentStandingsMonth($business);

        if ($month === null) {
            return null;
        }

        if (! $this->promptGatesPass($business)) {
            return null;
        }

        $standings = \App\Services\Campaigns\TopReferrerStandings::leadersForMonth($business, $month);

        if ($standings === null) {
            return null;
        }

        $unsent = array_values(array_filter(
            $standings['leaders'],
            fn ($leader) => ! \App\Services\Campaigns\TopReferrerStandings::alreadySent($business, $month, $leader->id),
        ));

        if ($unsent === []) {
            return null;
        }

        $monthName = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->format('F');
        $names = array_map(
            fn ($leader) => \App\Services\Campaigns\TopReferrerStandings::displayName($leader),
            $unsent,
        );
        $count = $standings['count'];

        // The line branches on the STANDINGS FORM, never on
        // the unsent count (two-key Condition 3, 2026-10-28):
        // a tie month keeps TR-02's tie wording for the
        // unsent remainder, including a single remaining
        // co-top after a partial send — TR-01's sole form
        // (line and label) renders only for a sole leader.
        if ($standings['form'] === 'sole') {
            $line = "Your top referrer in {$monthName}: {$names[0]}, with {$count} friends who visited.";
            $actionLabel = 'Send a thank-you text';
        } else {
            $listed = count($names) === 1
                ? $names[0]
                : (count($names) === 2
                    ? $names[0].' and '.$names[1]
                    : (count($names) === 3
                        ? $names[0].', '.$names[1].', and '.$names[2]
                        : $names[0].', '.$names[1].', and '.(count($names) - 2).' more'));
            $line = "Your top referrers in {$monthName}: {$listed}, with {$count} friends who visited each.";
            $actionLabel = 'Send thank-you texts';
        }

        if ($this->recordPromptShown) {
            \App\Services\Campaigns\CampaignPrompts::recordTopReferrerShown($business, $month);
        }

        $leaderIds = implode(',', array_map(fn ($leader) => $leader->id, $standings['leaders']));

        return [
            'key' => 'top_referrer_recognition',
            'urgency' => 85,
            'line' => $line,
            'action_label' => $actionLabel,
            'url' => route('campaigns.index'),
            'post_url' => route('campaigns.recognition'),
            'post_fields' => ['standings_month' => $month],
            'fingerprint' => 'topref|'.$month.'|'.$leaderIds.'|'.$count.'|'.$standings['form'],
            'dismissible' => true,
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
            // SA-01 (Amendment 35): the row carries the same
            // time line as the banner and the gate, from the
            // same source, so the date (or the paused truth)
            // reaches this surface too.
            'line' => \App\Support\BillingCopy::pastDueLine().' '.\App\Support\BillingCopy::statusLine($business),
            'action_label' => 'Update card',
            'url' => route('settings.billing').'#card',
            'fingerprint' => 'past_due|'.($latestFailure ?? 0),
            'dismissible' => false,
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
            'dismissible' => true,
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

        // Train A2 (F-15): the row names a charge date, so it
        // renders only when a charge on that date is real (the
        // shared forecast gate: combined total at or over the
        // minimum, card on file). The gate is read only after
        // the cheap date predicate passes, so the common path
        // gains no queries. Live-rendered: a business that
        // starts accruing later gains the row while it can act.
        if (! \App\Support\BillingForecast::chargeIsExpected($business)) {
            return null;
        }

        return [
            'key' => 'card_expires_before_charge',
            'urgency' => 25,
            'line' => 'Your card expires before your next charge on '
                .\App\Support\BillingForecast::nextChargeDateFor($business)->format('F j')
                .'. Replace it so the charge goes through.',
            'action_label' => 'Replace card',
            'url' => route('settings.billing').'#card',
            'fingerprint' => 'card_expires|'.$card->id,
            'dismissible' => true,
        ];
    }

    /**
     * The standing no-chargeable-card row (AC-11; coherence
     * Phase 2, G4), re-derived by the activation redesign
     * (census C-25): the row's population is now EVERY business
     * with no card on file — never-carded owners are its
     * primary audience, not only owners who removed a card —
     * because under the allowance model the card is the ask
     * that keeps a live code on, and the checklist (where the
     * card is now an optional row) no longer carries that ask
     * as a blocker. The removed-card precondition is deleted;
     * the row keys on the card fact plus the allowance state,
     * and its truth splits by that state:
     *
     * - allowance left [ACT-AC-01a]: the charges framing plus
     *   the visits left (at exactly 1 left this form IS the
     *   Section 6.2 at-1 warning [ACT-VIS-02] — same row, same
     *   surface, the warning's own words);
     * - allowance spent [ACT-AC-01b]: the paused framing.
     *
     * Not past due (AC-01 owns the failed-charge state). The
     * G4 stand-down contract with the setup row is re-derived
     * under the new required set: while this row fires, the
     * setup row stands down for the card state; the setup
     * row's own gate (required steps open) never includes the
     * card any more, so the two rows divide cleanly — this row
     * owns the card ask whenever the rest of required setup is
     * done, whatever the owner's card history.
     *
     * @return Item|null
     */
    private function noCardOnFile(Business $business, User $user, ?PaymentMethod $card, ?array $checklist = null): ?array
    {
        if ($card !== null || $business->billing_status === 'past_due') {
            return null;
        }

        $checklist ??= SetupChecklist::for($business, $user);

        // ON-SET-2/3: only REQUIRED steps gate this row — an
        // open optional step (the review link, and now the
        // card itself) never keeps the no-card state silent.
        foreach (SetupChecklist::required($checklist) as $step => $done) {
            if (! $done) {
                return null;
            }
        }

        $left = $business->allowanceLeft();

        if ($left === 0) {
            // ACT-AC-01b: the code is paused for want of a card.
            $line = 'Your code is paused. Your 5 free visits are used up. Add a card to turn your code back on.';
            $fingerprint = 'no_card|paused';
        } elseif ($left === 1) {
            // ACT-VIS-02: the at-1 warning, on the row the
            // owner already reads for this state.
            $line = 'One free visit left. After it, your code pauses until you add a card. Visits you have already earned still finish as usual.';
            $fingerprint = 'no_card|one_left';
        } else {
            // ACT-AC-01a: charges framing + the visits left.
            $line = 'You have no card on file. You have '.$left.' of your 5 free visits left, and your code stays on while they last.';
            $fingerprint = 'no_card|allowance|'.$left;
        }

        return [
            'key' => 'no_card_on_file',
            'urgency' => 15,
            'line' => $line,
            'action_label' => 'Add a card',
            'url' => route('settings.billing').'#card',
            'fingerprint' => $fingerprint,
            'dismissible' => true,
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
    private function reviewLinkMissing(Business $business, ?bool $hasRedeemedReferral = null): ?array
    {
        if ($business->google_review_url !== null && trim((string) $business->google_review_url) !== '') {
            return null;
        }

        // B7 M-7: the dashboard's counters already answered
        // "any redeemed referral?" this request (completed_total
        // > 0 is the same fact); when that answer is handed
        // over, the exists() probe is not run again.
        $hasRedemption = $hasRedeemedReferral ?? \App\Support\DemoExclusion::referrals(\App\Models\Referral::query())
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
            'dismissible' => true,
        ];
    }

    /**
     * The one card-expiry rule, on the model (SA-07,
     * Amendment 35): a card is expired once its expiry month
     * has passed. This helper stays for its two callers in
     * this class and delegates, so the Action Center can
     * never drift from the Billing page and Settings index.
     */
    public static function cardIsExpired(PaymentMethod $card): bool
    {
        return $card->isExpired();
    }

    /**
     * The review queue (AC-03 as amended, coherence Phase 2
     * G5): ONE row for the whole queue, however its items aged.
     * A hold that has waited past the too-long threshold folds
     * into this same row as a trailing clause — the state is
     * one ("things are waiting for your review"), and one state
     * is one row counted once. The retired AC-04 row asserted
     * the same wait a second time and double-counted the badge.
     *
     * @param  array{held_referrals?: \Illuminate\Support\Collection<int, Referral>, held_rewards?: \Illuminate\Support\Collection<int, Reward>}|null  $shared
     * @return Item|null
     */
    private function reviewHolds(Business $business, ?array $shared = null): ?array
    {
        // B7 M-1: when the caller already fetched the held
        // collections this request (the dashboard, for its
        // tiles and the shell badge), those exact rows are
        // aged and fingerprinted here instead of being
        // fetched a third time.
        // Demo double exclusion (A-18 Finding 3) on the
        // fallback fetches, matching the dashboard's shared sets.
        $referrals = $shared['held_referrals'] ?? \App\Support\DemoExclusion::referrals(Referral::query())
            ->where('business_id', $business->id)
            ->where('hold', true)
            ->get(['id', 'held_at']);
        $rewards = $shared['held_rewards'] ?? \App\Support\DemoExclusion::rewards(Reward::query())
            ->where('business_id', $business->id)
            ->where('hold', true)
            ->get(['id', 'held_at']);

        $count = $referrals->count() + $rewards->count();

        if ($count === 0) {
            return null;
        }

        $line = $count === 1
            ? '1 item is waiting for your review.'
            : $count.' items are waiting for your review.';

        $fingerprint = 'holds|'.md5(
            $referrals->pluck('id')->sort()->implode(',').';'.$rewards->pluck('id')->sort()->implode(','),
        );

        // The stale clause: the oldest hold across both kinds,
        // once it has waited past the threshold. The stale
        // item's identity rides the fingerprint, so dismissing
        // the row while one item is stale cannot hide the row
        // when another item goes stale later.
        $oldest = collect([
            ...$referrals->map(fn ($row) => ['kind' => 'referral', 'id' => $row->id, 'held_at' => $row->held_at]),
            ...$rewards->map(fn ($row) => ['kind' => 'reward', 'id' => $row->id, 'held_at' => $row->held_at]),
        ])->filter(fn (array $row) => $row['held_at'] !== null)
            ->sortBy('held_at')
            ->first();

        if ($oldest !== null && $oldest['held_at']->lte(now()->subDays(self::HELD_TOO_LONG_DAYS))) {
            $line .= ' One has been waiting '.AgeLabel::of($oldest['held_at']).'.';
            $fingerprint .= '|stale:'.$oldest['kind'].':'.$oldest['id'];
        }

        return [
            'key' => 'review_holds',
            'urgency' => 40,
            'line' => $line,
            'action_label' => 'Open review',
            'url' => route('reviews'),
            'fingerprint' => $fingerprint,
            'dismissible' => true,
        ];
    }

    /**
     * M-4 [M4-AC-01] (design pass Sections 2.2, 2.4): ONE
     * aggregate row while at least one ready reward is inside
     * its last warning window, never one row per reward. The
     * count reads readyToClaim() rows only (available, unheld:
     * a held reward's clock is paused and it renders no date
     * anywhere), and the fingerprint keys on the sorted ids
     * inside the window, so a new reward entering the window
     * re-shows a dismissed row. Callers run the set-level
     * on-read transitions before this read (the dashboard does),
     * so a reward that expired this morning is already out.
     *
     * @return Item|null
     */
    private function expiringRewards(Business $business): ?array
    {
        $warningDays = (int) config('referrals.reward_expiry_warning_days', 30);

        $ids = \App\Support\DemoExclusion::rewards(Reward::query())
            ->where('business_id', $business->id)
            ->readyToClaim()
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays($warningDays))
            ->orderBy('id')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return null;
        }

        $count = $ids->count();

        return [
            'key' => 'rewards_expiring',
            'urgency' => 45,
            'line' => $count === 1
                ? '1 customer reward runs out in the next 30 days.'
                : $count.' customer rewards run out in the next 30 days.',
            'action_label' => 'See rewards',
            'url' => route('rewards.index'),
            'fingerprint' => 'expiring|'.md5($ids->implode(',')),
            'dismissible' => true,
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
            'dismissible' => true,
        ];
    }

    /**
     * @return Item|null
     */
    private function setupChecklist(Business $business, User $user, ?array $checklist = null, bool $ownedByNoCardRow = false): ?array
    {
        // G4: while the no-card row carries the removed-card
        // state, the setup row stands down — the same missing
        // card never produces two rows.
        if ($ownedByNoCardRow) {
            return null;
        }

        $checklist ??= SetupChecklist::for($business, $user);
        [$done, $total] = SetupChecklist::progress($checklist);

        if ($done >= $total) {
            return null;
        }

        // ON-SET-2: $done/$total are SetupChecklist::progress(),
        // the required count, so this row's words agree with
        // the checklist card and the dashboard by construction.
        $line = 'Your setup is not finished: '.$done.' of '.$total.' required steps done.';

        // G1 (coherence Phase 2; AC-07 as amended): when one
        // activation leg is the thing between the owner and a
        // live code, the row names that blocker in the same
        // line, so the row itself teaches the dependency the
        // poster step used to hide.
        //
        // F-2 addendum (Amendment 40, F2-GATE-02; coordinator
        // ruling on the F-2 build report's flagged observation):
        // activation is status + friend reward text + card
        // (flowIsActive), so the clause branches in the
        // F2-GATE-01 pattern — the rewards leg is checked
        // FIRST, in the checklist's own order, in any card
        // state: a card-holding owner whose friend reward text
        // is missing must never be told (or left) with the card
        // as the named prerequisite, and in the both-missing
        // state the card clause alone was incomplete in the
        // same way FAQ-07 was. The card clause below is
        // reachable only when the texts are set and billing
        // alone is off. When both legs hold (or the account
        // itself is not active, whose surfaces own that truth
        // words-only, QR-INACTIVE-01b) no clause renders. In
        // past_due the rewards clause can render here while
        // the past-due row above carries the billing truth;
        // the card clause stays excluded there as before,
        // because a past-due business has a card.
        if ($business->isActive() && ! $business->hasFriendRewardText()) {
            $line .= ' Your QR code is not active until you set your friend reward.';
        } elseif ($business->isActive() && $business->flowBlockReason() === 'allowance_exhausted') {
            // Activation redesign (census addendum C-37): the
            // card clause's valid states re-derive with the
            // gate — a cardless business with allowance left
            // IS active, so the clause renders only when the
            // allowance is the actual block reason, never on
            // billing_status alone.
            $line .= ' Your QR code is not active until you add a card.';
        }

        // P4 (OB-U6): while the onboarding flow is open, the
        // row resumes the flow at its first open step; once the
        // flow is completed (or every flow step is done and only
        // non-flow steps remain), it points at the dashboard as
        // before. States come from the checklist already
        // computed above, so the row adds no queries.
        $url = route('dashboard');
        if ($business->onboarding_completed_at === null) {
            $openStep = Onboarding::firstOpenStep(Onboarding::states($business, $user, $checklist));
            if ($openStep !== null) {
                $url = route('onboarding.step', $openStep);
            }
        }

        return [
            'key' => 'setup_checklist',
            'urgency' => 70,
            'line' => $line,
            'action_label' => 'Finish setup',
            'url' => $url,
            'fingerprint' => self::setupFingerprint($checklist),
            'dismissible' => true,
        ];
    }

    /**
     * The setup row's state fingerprint (coherence Phase 2,
     * G7/S8c): the COMPOSITION of the open steps — which steps
     * are open — never the finished count alone. Two states
     * with the same count and different open steps are
     * different states: a step that reopens (a card removed, a
     * link cleared) resurfaces a dismissed row even when
     * another step closed in the meantime and the count never
     * moved. The dashboard's dismissed flag reads this same
     * fingerprint (G6: one dismissal domain).
     *
     * @param  array<string, bool>  $checklist
     */
    public static function setupFingerprint(array $checklist): string
    {
        // ON-SET-2: the fingerprint keys on the REQUIRED open
        // steps only. The setup row and card exist only while
        // a required step is open, so an optional step opening
        // or closing (the review link set or cleared) must not
        // resurface a dismissed setup state the owner already
        // saw — the required composition is the state.
        $open = array_keys(array_filter(SetupChecklist::required($checklist), fn (bool $done) => ! $done));
        sort($open);

        return 'checklist|'.implode(',', $open);
    }

}
