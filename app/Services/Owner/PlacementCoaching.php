<?php

namespace App\Services\Owner;

use App\Models\ActionCenterDismissal;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\PlacementCoachingEvent;
use App\Models\QrCode;
use App\Models\ReferralEvent;
use Illuminate\Support\Carbon;

/**
 * ID-04 placement coaching (design pass idea-04-design-pass.md):
 * one comparison card on the owner's QR page, rendered only
 * when a pair of placements clears a conjunctive floor over one
 * COMMON window. The design governs every constant here.
 *
 * Data truths the class is built on (COMPANY-KNOWLEDGE):
 * placementStats() is all-time only, so the windowed sibling
 * read below is the one new read (no new storage for counts);
 * a "scan" is a completed verified enrollment through a code,
 * counted by PLACEMENT (metadata), never by qr_code_id, so a
 * replace never erases history the placement earned; no
 * per-placement visit or revenue number exists, and no string
 * here claims one.
 *
 * Normalization (Section 1.4): W = min(28 days, age of the
 * youngest eligible code); both sides are counted over the same
 * trailing W days, so scans-in-W is the rate with equal exposure
 * and equal calendar days. Lifetime totals and per-code eras
 * are never compared. V1 renders only at W = 28 (COACH-03), so
 * the one window phrase "in the last 4 weeks" is always true.
 */
class PlacementCoaching
{
    /** The dismissal table key, borrowed from the Action Center pattern. */
    public const DISMISSAL_ITEM_KEY = 'placement_coaching';

    /** F2: a code is eligible only once its current code is this old. */
    public const MIN_CODE_AGE_DAYS = 14;

    /** Section 1.4: the common window's cap and floor. */
    public const WINDOW_MAX_DAYS = 28;

    public const WINDOW_MIN_DAYS = 14;

    /** F4: the benchmark must be real. */
    public const BEST_MIN_SCANS = 8;

    /** F5: the loser must have a pulse; zero is SA-06's state. */
    public const WORST_MIN_SCANS = 1;

    /** F6: the gap must be material, on both limbs. */
    public const GAP_MIN_SCANS = 6;

    public const RATIO_MIN = 3.0;

    /** Section 5.3: how long an acted-on card stays down. */
    public const ACTED_SUPPRESSION_DAYS = 28;

    /** Section 5.3: no different card right after an act or dismissal. */
    public const GLOBAL_COOLDOWN_DAYS = 7;

    /** L4d: three dismissals in a row snooze all coaching. */
    public const SNOOZE_DISMISSALS = 3;

    public const SNOOZE_DAYS = 90;

    /** Section 7.1: an act counts when it follows an impression within this window. */
    public const ACTED_WITHIN_DAYS = 14;

    /** Poster variant -> the placement whose code it prints (the controller's map). */
    private const VARIANT_PLACEMENT = [
        'counter' => 'counter',
        'mini' => 'counter',
        'window' => 'window',
        'tent' => 'table',
        'receipt' => 'receipt',
    ];

    /**
     * The move table (Section 4.2), keyed by the COACHED
     * placement only: the benchmark's identity never changes
     * the move. move_key is the fingerprint's move component;
     * suggested_placement is the artifact the move prints.
     *
     * @var array<string, array{move_key: string, move_sentence: string, action_label: string, suggested_placement: string, action_variant: string, action_multi: bool}>
     */
    private const MOVES = [
        'counter' => [
            'move_key' => 'receipt_cards',
            'move_sentence' => 'Try a stack of receipt cards by the register, handed out with every receipt.',
            'action_label' => 'Print a sheet of cards',
            'suggested_placement' => 'receipt',
            'action_variant' => 'receipt',
            'action_multi' => true,
        ],
        'window' => [
            'move_key' => 'counter_poster',
            'move_sentence' => 'Try a counter poster by the register, where every paying customer stands.',
            'action_label' => 'Print the counter poster',
            'suggested_placement' => 'counter',
            'action_variant' => 'counter',
            'action_multi' => false,
        ],
        'table' => [
            'move_key' => 'counter_poster',
            'move_sentence' => 'Try a counter poster by the register, where every paying customer stands.',
            'action_label' => 'Print the counter poster',
            'suggested_placement' => 'counter',
            'action_variant' => 'counter',
            'action_multi' => false,
        ],
        'receipt' => [
            'move_key' => 'receipt_habit',
            'move_sentence' => 'Try keeping the card stack right by the register, and hand one out with every receipt.',
            'action_label' => 'Print a sheet of cards',
            'suggested_placement' => 'receipt',
            'action_variant' => 'receipt',
            'action_multi' => true,
        ],
    ];

    /**
     * The common window for this business right now: min(28,
     * youngest eligible code's age in days), or null when fewer
     * than two codes are eligible or the window would fall
     * under the 14-day floor (Section 1.4, F2/F3).
     */
    public function windowDaysFor(Business $business, ?Carbon $now = null): ?int
    {
        $now ??= now();
        $ages = $this->eligibleAges($business, $now);

        if (count($ages) < 2) {
            return null;
        }

        $window = min(self::WINDOW_MAX_DAYS, min($ages));

        return $window >= self::WINDOW_MIN_DAYS ? $window : null;
    }

    /**
     * Per-placement scan counts over the trailing $days window:
     * the windowed sibling of QrPageController::placementStats(),
     * over the same scan events with the same bucketing
     * (metadata placement, legacy events to the counter), plus
     * the created_at >= window-start filter. Counts only; the
     * all-time rows are untouched.
     *
     * @return array<string, int>
     */
    public function windowedScanCounts(Business $business, int $days, ?Carbon $now = null): array
    {
        $now ??= now();
        $counts = array_fill_keys(QrCode::PLACEMENTS, 0);

        $events = ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'scan')
            ->where('created_at', '>=', $now->copy()->subDays($days))
            ->where('created_at', '<=', $now)
            ->get(['metadata', 'created_at']);

        foreach ($events as $event) {
            $placement = $event->metadata['placement'] ?? 'counter';
            if (! isset($counts[$placement])) {
                $placement = 'counter';
            }
            $counts[$placement]++;
        }

        return $counts;
    }

    /**
     * The floor evaluation (Sections 1.5 and 4.1), suppression
     * NOT applied: the qualifying (best, worst) pair with its
     * counts, move, and fingerprint, or null when any conjunct
     * fails. V1 renders only at W = 28 (COACH-03), so a shorter
     * common window is silence here even though the data floor
     * (F3) is stated at 14 for a future variant.
     *
     * @return array{best: string, worst: string, best_scans: int, worst_scans: int, gap: int, window_days: int, move_key: string, move_sentence: string, action_label: string, action_variant: string, action_multi: bool, suggested_placement: string, fingerprint: string, body: string}|null
     */
    public function assess(Business $business, ?Carbon $now = null): ?array
    {
        $now ??= now();

        // F1: frozen data never coaches.
        if (! $business->flowIsActive()) {
            return null;
        }

        // F2/F3: at least two eligible codes and a real window.
        $window = $this->windowDaysFor($business, $now);
        if ($window === null) {
            return null;
        }

        // COACH-03 (v1): exactly one true window phrase exists,
        // so the card renders only when the window is 28 days.
        if ($window !== self::WINDOW_MAX_DAYS) {
            return null;
        }

        $ages = $this->eligibleAges($business, $now);
        $counts = $this->windowedScanCounts($business, $window, $now);
        $eligible = array_keys($ages);

        // Section 4.1 step 2: unique best, unique worst among
        // the eligible codes. Any tie is silence: a tied
        // "winner" would make the sentence dishonest by omission.
        $best = null;
        $worst = null;
        foreach ($eligible as $placement) {
            if ($best === null || $counts[$placement] > $counts[$best]) {
                $best = $placement;
            }
            if ($worst === null || $counts[$placement] < $counts[$worst]) {
                $worst = $placement;
            }
        }
        $bestCount = $counts[$best];
        $worstCount = $counts[$worst];
        $atBest = count(array_filter($eligible, fn (string $p) => $counts[$p] === $bestCount));
        $atWorst = count(array_filter($eligible, fn (string $p) => $counts[$p] === $worstCount));
        if ($atBest !== 1 || $atWorst !== 1 || $best === $worst) {
            return null;
        }

        // F4, F5, F6: benchmark real, loser has a pulse, gap
        // material on both limbs.
        if ($bestCount < self::BEST_MIN_SCANS
            || $worstCount < self::WORST_MIN_SCANS
            || ($bestCount - $worstCount) < self::GAP_MIN_SCANS
            || ($bestCount / $worstCount) < self::RATIO_MIN) {
            return null;
        }

        $move = self::MOVES[$worst];
        $gap = $bestCount - $worstCount;
        $codes = $this->activeCodes($business);
        $bestName = $codes[$best]->displayName();
        $worstName = $codes[$worst]->displayName();

        $body = $bestName.': '.self::scanPhrase($bestCount).' in the last 4 weeks. '
            .$worstName.': '.self::countPhrase($worstCount).'. '
            .$move['move_sentence'];

        return [
            'best' => $best,
            'worst' => $worst,
            'best_scans' => $bestCount,
            'worst_scans' => $worstCount,
            'gap' => $gap,
            'window_days' => $window,
            'move_key' => $move['move_key'],
            'move_sentence' => $move['move_sentence'],
            'action_label' => $move['action_label'],
            'action_variant' => $move['action_variant'],
            'action_multi' => $move['action_multi'],
            'suggested_placement' => $move['suggested_placement'],
            'fingerprint' => self::fingerprint($worst, $best, $move['move_key'], $gap),
            'body' => $body,
        ];
    }

    /**
     * The renderable card: assess() plus the SA-06 precedence
     * check and the full suppression contract (Section 5.3).
     * Null means the QR page renders no coaching surface at all.
     *
     * @param  array<string, bool>|null  $staleMap  the QR page's own per-placement stale flags when the caller already computed them (the controller passes its rows'), so the two voices read one staleness derivation
     * @return array<string, mixed>|null
     */
    public function cardFor(Business $business, ?array $staleMap = null, ?Carbon $now = null): ?array
    {
        $now ??= now();
        $candidate = $this->assess($business, $now);

        if ($candidate === null) {
            return null;
        }

        // SA-06 precedence (Section 1.6): unreachable by the
        // window proof (a named code's last scan is inside 28
        // days, staleness needs 30), and shipped anyway, because
        // the two voices share a page and must never name the
        // same code in the same render.
        foreach ([$candidate['best'], $candidate['worst']] as $placement) {
            $stale = $staleMap[$placement] ?? $this->placementIsStale($business, $placement, $now);
            if ($stale) {
                return null;
            }
        }

        if ($this->isSuppressed($business, $candidate, $now)) {
            return null;
        }

        return $candidate;
    }

    /**
     * SA-06's staleness for one placement, on the shipped rule
     * (QrPageController, Amendment 41): silent since the later
     * of the current code's Added date and its last scan; stale
     * once that instant is more than 30 days past.
     */
    public function placementIsStale(Business $business, string $placement, ?Carbon $now = null): bool
    {
        $now ??= now();
        $code = QrCode::activeForPlacement($business, $placement);
        $lastScanAt = ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('event_type', 'scan')
            ->where('metadata->placement', $placement)
            ->max('created_at');
        // Legacy events carry no placement and belong to the
        // counter, exactly as placementStats buckets them.
        if ($placement === 'counter') {
            $legacy = ReferralEvent::query()
                ->where('business_id', $business->id)
                ->where('event_type', 'scan')
                ->whereNull('metadata')
                ->max('created_at');
            if ($legacy !== null && ($lastScanAt === null || $legacy > $lastScanAt)) {
                $lastScanAt = $legacy;
            }
        }

        $silentSince = $lastScanAt !== null ? Carbon::parse($lastScanAt) : null;
        if ($code !== null && $code->created_at !== null
            && ($silentSince === null || $code->created_at->gt($silentSince))) {
            $silentSince = $code->created_at;
        }

        return $silentSince !== null && $silentSince->copy()->addDays(30)->lt($now);
    }

    // ---------- Recording (Sections 5.3, 7.1) ----------

    /**
     * Record one impression of a rendered card (Section 7.1):
     * at most one per business per fingerprint per
     * business-local day.
     *
     * @param  array<string, mixed>  $candidate
     */
    public function recordImpression(Business $business, array $candidate, ?Carbon $now = null): void
    {
        $now ??= now();
        $localDay = $business->localTime($now)->format('Y-m-d');
        $dayStartLocal = Carbon::parse($localDay.' 00:00:00', $business->localTime($now)->getTimezone());
        $dayStart = $dayStartLocal->copy()->setTimezone('UTC');
        $dayEnd = $dayStart->copy()->addDay();

        $exists = PlacementCoachingEvent::query()
            ->where('business_id', $business->id)
            ->where('kind', PlacementCoachingEvent::KIND_IMPRESSION)
            ->where('fingerprint', $candidate['fingerprint'])
            ->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayEnd)
            ->exists();

        if (! $exists) {
            $this->writeEvent($business, PlacementCoachingEvent::KIND_IMPRESSION, $candidate);
        }
    }

    /**
     * Record a dismissal: the event row (the snooze/cooldown
     * history) and the current-state dismissal in the Action
     * Center's table, following its fingerprint pattern
     * (Section 5.3).
     *
     * @param  array<string, mixed>  $candidate
     */
    public function recordDismissal(Business $business, array $candidate): void
    {
        $this->writeEvent($business, PlacementCoachingEvent::KIND_DISMISSED, $candidate);

        ActionCenterDismissal::query()->updateOrCreate(
            ['business_id' => $business->id, 'item_key' => self::DISMISSAL_ITEM_KEY],
            ['state_fingerprint' => $candidate['fingerprint']],
        );
    }

    /**
     * The measurement derivation (Section 7.1 item 2): did a
     * qualifying acted signal land within 14 days after this
     * impression? Signals are the shipped events: a print of
     * the suggested artifact or of the coached placement's own
     * artifact, or a replace of the coached placement.
     */
    public function actedWithin14Days(PlacementCoachingEvent $impression): bool
    {
        $business = $impression->business;
        if ($business === null) {
            return false;
        }

        foreach ($this->actedSignals($business) as $signal) {
            if ($signal['pair_key'] !== self::pairKey($impression->worst_placement, $impression->best_placement, $impression->move_key)) {
                continue;
            }
            $at = $signal['at'];
            if (! $at->lt($impression->created_at)
                && $at->lte($impression->created_at->copy()->addDays(self::ACTED_WITHIN_DAYS))) {
                return true;
            }
        }

        return false;
    }

    // ---------- Suppression (Section 5.3, exact) ----------

    /**
     * @param  array<string, mixed>  $candidate
     */
    public function isSuppressed(Business $business, array $candidate, ?Carbon $now = null): bool
    {
        $now ??= now();

        // The 90-day snooze silences every card.
        $snoozedUntil = $this->snoozedUntil($business);
        if ($snoozedUntil !== null && $now->lt($snoozedUntil)) {
            return true;
        }

        $pairKey = self::pairKey($candidate['worst'], $candidate['best'], $candidate['move_key']);

        // Dismissal: hidden until the state materially changes
        // (a different pair or move, or the gap grown by half).
        // Time alone never resurfaces a dismissed fingerprint.
        $dismissals = $this->dismissals($business);
        foreach ($dismissals as $dismissal) {
            if (self::pairKey($dismissal->worst_placement, $dismissal->best_placement, $dismissal->move_key) === $pairKey
                && $candidate['gap'] < (int) ceil($dismissal->gap * 1.5)) {
                return true;
            }
        }

        // Acted: 28 days from a qualifying print or replace that
        // followed an impression of this same comparison.
        $acted = $this->latestActedAt($business, $pairKey);
        if ($acted !== null && $now->lt($acted->copy()->addDays(self::ACTED_SUPPRESSION_DAYS))) {
            return true;
        }

        // Global cooldown: after ANY act or dismissal, no NEW
        // (different pair or move) card for 7 days. A resurfaced
        // same-comparison card is not a new card.
        $lastDismissal = $dismissals->first();
        if ($lastDismissal !== null
            && self::pairKey($lastDismissal->worst_placement, $lastDismissal->best_placement, $lastDismissal->move_key) !== $pairKey
            && $now->lt($lastDismissal->created_at->copy()->addDays(self::GLOBAL_COOLDOWN_DAYS))) {
            return true;
        }
        $lastActed = $this->latestActedAt($business, null);
        if ($lastActed !== null) {
            $actedPair = $this->actedPairKeyAt($business, $lastActed);
            if ($actedPair !== null && $actedPair !== $pairKey
                && $now->lt($lastActed->copy()->addDays(self::GLOBAL_COOLDOWN_DAYS))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The instant the current 3-in-a-row snooze runs out, or
     * null. Dismissals and acted signals are walked in time
     * order: an acted event resets the streak (L4d: "no acted
     * event between"); the third consecutive dismissal starts
     * a 90-day snooze from its own instant, and any further
     * dismissal inside the streak re-anchors it.
     */
    public function snoozedUntil(Business $business): ?Carbon
    {
        $events = [];
        foreach ($this->dismissals($business) as $dismissal) {
            $events[] = ['at' => $dismissal->created_at, 'kind' => 'dismissed'];
        }
        foreach ($this->actedSignals($business) as $signal) {
            $events[] = ['at' => $signal['at'], 'kind' => 'acted'];
        }
        usort($events, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        $streak = 0;
        $until = null;
        foreach ($events as $event) {
            if ($event['kind'] === 'acted') {
                $streak = 0;
                continue;
            }
            $streak++;
            if ($streak >= self::SNOOZE_DISMISSALS) {
                $until = $event['at']->copy()->addDays(self::SNOOZE_DAYS);
            }
        }

        return $until;
    }

    // ---------- Internals ----------

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function writeEvent(Business $business, string $kind, array $candidate): PlacementCoachingEvent
    {
        return PlacementCoachingEvent::create([
            'business_id' => $business->id,
            'kind' => $kind,
            'fingerprint' => $candidate['fingerprint'],
            'worst_placement' => $candidate['worst'],
            'best_placement' => $candidate['best'],
            'move_key' => $candidate['move_key'],
            'gap' => $candidate['gap'],
            'best_scans' => $candidate['best_scans'],
            'worst_scans' => $candidate['worst_scans'],
            'window_days' => $candidate['window_days'],
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, PlacementCoachingEvent> newest first */
    private function dismissals(Business $business): \Illuminate\Support\Collection
    {
        return PlacementCoachingEvent::query()
            ->where('business_id', $business->id)
            ->where('kind', PlacementCoachingEvent::KIND_DISMISSED)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Every acted signal for the business, derived from shipped
     * events (Section 7.1): poster prints from the audit log
     * (the only print record that names its variant) and code
     * replacements (a placement whose current code has a revoked
     * predecessor was replaced at the current code's issuance).
     * Each signal is attributed to the comparison it answers:
     * the impression of the same pair key nearest before it
     * within 14 days; unattributed signals are dropped, because
     * an act is defined relative to an impression.
     *
     * @return list<array{at: Carbon, pair_key: string}>
     */
    private function actedSignals(Business $business): array
    {
        $impressions = PlacementCoachingEvent::query()
            ->where('business_id', $business->id)
            ->where('kind', PlacementCoachingEvent::KIND_IMPRESSION)
            ->orderBy('created_at')
            ->get();

        if ($impressions->isEmpty()) {
            return [];
        }

        $raw = [];

        $prints = AuditLog::query()
            ->where('business_id', $business->id)
            ->where('action', 'qr.poster_downloaded')
            ->orderBy('created_at')
            ->get();
        foreach ($prints as $print) {
            $variant = $print->metadata['variant'] ?? null;
            if (! is_string($variant) || ! isset(self::VARIANT_PLACEMENT[$variant])) {
                continue;
            }
            $raw[] = ['at' => $print->created_at, 'printed_placement' => self::VARIANT_PLACEMENT[$variant], 'replaced_placement' => null];
        }

        foreach (QrCode::PLACEMENTS as $placement) {
            $code = QrCode::activeForPlacement($business, $placement);
            if ($code === null) {
                continue;
            }
            $hasPredecessor = QrCode::query()
                ->where('business_id', $business->id)
                ->where('placement', $placement)
                ->where('state', 'revoked')
                ->exists();
            if ($hasPredecessor) {
                $raw[] = ['at' => $code->created_at, 'printed_placement' => null, 'replaced_placement' => $placement];
            }
        }

        $signals = [];
        foreach ($raw as $signal) {
            $pairKey = $this->attributeSignal($impressions, $signal);
            if ($pairKey !== null) {
                $signals[] = ['at' => $signal['at'], 'pair_key' => $pairKey];
            }
        }

        return $signals;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PlacementCoachingEvent>  $impressions
     * @param  array{at: Carbon, printed_placement: ?string, replaced_placement: ?string}  $signal
     */
    private function attributeSignal(\Illuminate\Support\Collection $impressions, array $signal): ?string
    {
        $match = null;
        foreach ($impressions as $impression) {
            // Inclusive at the impression's own instant: the
            // card's action is clicked from the very page whose
            // render recorded the impression, and timestamps
            // carry second precision, so a same-second act is
            // a real act on this card, not a prior one.
            if ($signal['at']->lt($impression->created_at)
                || $signal['at']->gt($impression->created_at->copy()->addDays(self::ACTED_WITHIN_DAYS))) {
                continue;
            }
            $move = self::MOVES[$impression->worst_placement] ?? null;
            if ($move === null) {
                continue;
            }
            $qualifies = $signal['replaced_placement'] !== null
                ? $signal['replaced_placement'] === $impression->worst_placement
                : in_array($signal['printed_placement'], [$move['suggested_placement'], $impression->worst_placement], true);
            if ($qualifies) {
                $match = $impression; // impressions ascend; the nearest before wins
            }
        }

        return $match === null
            ? null
            : self::pairKey($match->worst_placement, $match->best_placement, $match->move_key);
    }

    /** The latest acted instant for one pair, or for any pair when $pairKey is null. */
    private function latestActedAt(Business $business, ?string $pairKey): ?Carbon
    {
        $latest = null;
        foreach ($this->actedSignals($business) as $signal) {
            if ($pairKey !== null && $signal['pair_key'] !== $pairKey) {
                continue;
            }
            if ($latest === null || $signal['at']->gt($latest)) {
                $latest = $signal['at'];
            }
        }

        return $latest;
    }

    private function actedPairKeyAt(Business $business, Carbon $at): ?string
    {
        foreach ($this->actedSignals($business) as $signal) {
            if ($signal['at']->equalTo($at)) {
                return $signal['pair_key'];
            }
        }

        return null;
    }

    /** The fingerprint (Section 5.3): pair, move, and the gap bucket. */
    public static function fingerprint(string $worst, string $best, string $moveKey, int $gap): string
    {
        return 'coach|'.$worst.'|'.$best.'|'.$moveKey.'|'.$gap;
    }

    /** The comparison's identity without its gap bucket: pair + move. */
    public static function pairKey(string $worst, string $best, string $moveKey): string
    {
        return $worst.'|'.$best.'|'.$moveKey;
    }

    /** "{n} scans" / "1 scan", the count phrase that opens the comparison. */
    private static function scanPhrase(int $count): string
    {
        return $count === 1 ? '1 scan' : $count.' scans';
    }

    /** The closing count: bare above one, "1 scan" at one (COACH-02 grammar note). */
    private static function countPhrase(int $count): string
    {
        return $count === 1 ? '1 scan' : (string) $count;
    }

    /**
     * Ages in whole days of the eligible (14+ days) current
     * codes, keyed by placement. Age is computed on instants,
     * never calendar strings (design Section 9 item 2).
     *
     * @return array<string, int>
     */
    private function eligibleAges(Business $business, Carbon $now): array
    {
        $ages = [];
        foreach ($this->activeCodes($business) as $placement => $code) {
            if ($code === null || $code->created_at === null) {
                continue;
            }
            $age = (int) floor(($now->timestamp - $code->created_at->timestamp) / 86400);
            if ($age >= self::MIN_CODE_AGE_DAYS) {
                $ages[$placement] = $age;
            }
        }

        return $ages;
    }

    /** @return array<string, QrCode|null> the active code per placement */
    private function activeCodes(Business $business): array
    {
        $codes = [];
        foreach (QrCode::PLACEMENTS as $placement) {
            $codes[$placement] = QrCode::activeForPlacement($business, $placement);
        }

        return $codes;
    }
}
