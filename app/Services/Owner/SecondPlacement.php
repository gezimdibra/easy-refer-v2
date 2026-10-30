<?php

namespace App\Services\Owner;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\QrCode;
use App\Models\ReferralEvent;
use App\Models\SecondPlacementPrompt;
use App\Models\SecondPlacementPromptImpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ID-06 (Amendment 49; design pass idea-06-design-pass.md):
 * the second-code prompt at the first settled visit. ONE
 * Action Center row, once per business ever, sending nothing.
 *
 * SETTLED (Section 1.1 — ID-02's three-axis counting law,
 * adopted verbatim, never forked): a visit is settled iff
 * (1) its referral is redeemed, (2) a billing event exists
 * with coverage NOT IN (held, fraud_denied, refunded), and
 * (3) its referrer reward is not held and not denied. Vesting
 * never blocks. The first settled visit is the earliest by
 * SETTLEMENT INSTANT: redemption time for a never-held visit;
 * for a visit held at redemption, the release instant (the
 * review_release event — release clears held_at on the rows,
 * so the event log is the durable release record).
 *
 * PLACED (Section 1.3): all four codes exist from day one, so
 * "placed" is the owner's print, never issuance. The effective
 * placed set = {placements with a poster print} U {P1}, where
 * P1 is the placement the settling visit's referrer joined
 * through (their earliest scan event's metadata, bucketed
 * exactly as placementStats buckets it: no placement in the
 * metadata is the counter; no scan event at all is a link
 * join and P1 is unknown). Prints derive from the shipped
 * qr.poster_downloaded audit records, whose variant names the
 * placement (ID-04's derivation, same map).
 *
 * Detection is computed live on every read. Writes follow the
 * shipped shown-stamp discipline: badge/dashboard reads
 * (evaluate with $record = false) write NOTHING; the Action
 * Center page read stamps state transitions and one
 * impression per business-local day; dismissal stamps the
 * DISMISSED terminal from ActionCenter::dismiss().
 */
class SecondPlacement
{
    /** The Action Center source key (stable: dismiss route + tables). */
    public const ITEM_KEY = 'second_placement';

    /** Poster variant -> the placement whose code it prints (QrPageController's map). */
    private const VARIANT_PLACEMENT = [
        'counter' => 'counter',
        'mini' => 'counter',
        'window' => 'window',
        'tent' => 'table',
        'receipt' => 'receipt',
    ];

    /** Placement -> the poster variant its print action opens (QrPageController's map). */
    private const PLACEMENT_POSTER = [
        'counter' => 'counter',
        'window' => 'window',
        'table' => 'tent',
        'receipt' => 'receipt',
    ];

    /** [SC-03] spot lines: the placements' location sentences, verbatim. */
    private const SPOT_LINES = [
        'counter' => 'By the register, where people pay.',
        'receipt' => 'Handed out with the receipt.',
        'window' => 'In the front window, facing out.',
        'table' => 'Standing up on your tables.',
    ];

    /** [SC-05] action labels: the shipped print labels, verbatim. */
    private const PRINT_LABELS = [
        'counter' => 'Print the counter poster',
        'window' => 'Print the window poster',
        'table' => 'Print the table tent',
        'receipt' => 'Print the receipt card',
    ];

    public function windowDays(): int
    {
        return (int) config('campaigns.second_placement_window_days', 14);
    }

    /**
     * The FirstSettledVisit read (Section 8.1 item 1): the
     * earliest settled visit under the ONE predicate, with its
     * settlement instant. Public so the readout and the tests
     * read the same derivation the row renders from.
     *
     * @return array{referral_id: int, referrer_membership_id: int, settled_at: Carbon}|null
     */
    public function firstSettled(Business $business): ?array
    {
        $probe = $this->probe($business);

        return $probe['settled'];
    }

    /**
     * The coverage order (Section 4.1), as a pure function of
     * the placed set at settlement and the proven placement:
     * receipt first when the counter proved itself, otherwise
     * counter, then receipt, then window, then table. Returns
     * the first placement NOT in $placed, or null when every
     * placement is placed. (Whether the row may FIRE is the
     * gate in evaluate(): placed count < 2, Sections 1.1/5.1.)
     *
     * @param  list<string>  $placed
     */
    public function offerFor(array $placed, ?string $proven): ?string
    {
        $order = $proven === 'counter' ? ['receipt'] : [];
        foreach (['counter', 'receipt', 'window', 'table'] as $placement) {
            if (! in_array($placement, $order, true)) {
                $order[] = $placement;
            }
        }

        foreach ($order as $placement) {
            if (! in_array($placement, $placed, true)) {
                return $placement;
            }
        }

        return null;
    }

    /**
     * Per-placement earliest poster print, from the audit
     * records (Section 8.1 item 3). Variant-less records (the
     * code-only download) name no placement and are not
     * prints of a placement's artifact — ID-04's rule.
     *
     * @return array<string, Carbon> placement => earliest print instant
     */
    public function printTimes(Business $business): array
    {
        $times = [];

        $rows = AuditLog::query()
            ->where('business_id', $business->id)
            ->where('action', 'qr.poster_downloaded')
            ->orderBy('created_at')
            ->get(['metadata', 'created_at']);

        foreach ($rows as $row) {
            $variant = $row->metadata['variant'] ?? null;
            if (! is_string($variant) || ! isset(self::VARIANT_PLACEMENT[$variant])) {
                continue;
            }
            $placement = self::VARIANT_PLACEMENT[$variant];
            if (! isset($times[$placement]) && $row->created_at !== null) {
                $times[$placement] = $row->created_at;
            }
        }

        return $times;
    }

    /**
     * P1, the proven placement (Section 1.3): the placement of
     * the scan event the settling visit's referrer joined
     * through — their earliest scan event. No placement in its
     * metadata buckets to the counter (placementStats' rule);
     * no scan event at all means a link join and P1 is unknown.
     */
    public function provenPlacement(Business $business, int $referrerMembershipId): ?string
    {
        $event = ReferralEvent::query()
            ->where('business_id', $business->id)
            ->where('membership_id', $referrerMembershipId)
            ->where('event_type', 'scan')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['metadata']);

        if ($event === null) {
            return null;
        }

        $placement = $event->metadata['placement'] ?? 'counter';

        return in_array($placement, QrCode::PLACEMENTS, true) ? $placement : 'counter';
    }

    /**
     * Evaluate the prompt for one business. Returns the Action
     * Center item when the row is live, else null. With
     * $record = true (the Action Center page read) the state
     * transitions and the day's impression are stamped; with
     * false nothing is written.
     *
     * @return array{key: string, urgency: int, line: string, action_label: string, url: string, fingerprint: string, dismissible: bool, dismiss_label: string}|null
     */
    public function evaluate(Business $business, bool $record = false): ?array
    {
        $probe = $this->probe($business);
        $state = $probe['prompt'];

        // Fires once, ever: a terminal stamp ends the feature
        // for this business in every later state (Section 5.4).
        if ($state !== null && $state->isTerminal()) {
            return null;
        }

        $settled = $probe['settled'];

        if ($settled === null) {
            // L2: a held first visit arms the prompt silently.
            // Nothing renders, nothing is sent; the arming is
            // bookkeeping for the readout only.
            if ($record && $probe['armed'] !== null) {
                $this->stamp($business, $state, SecondPlacementPrompt::STATE_ARMED, $probe['armed']['referral_id'], null, null);
            }

            return null;
        }

        // A non-terminal stamp from an earlier trigger whose
        // visit no longer settles (refunded before any action,
        // L6) is stale: detection restarts from the visit that
        // settles now, and the stale stamp is replaced when
        // the page read next stamps.
        if ($state !== null && (int) $state->trigger_referral_id !== $settled['referral_id']) {
            $state = null;
        }

        $settledAt = $settled['settled_at'];
        $windowEnd = $settledAt->copy()->addDays($this->windowDays());
        $now = now();

        // Past the window on a non-recording read (badge /
        // dashboard), the row is gone and no stamp is owed:
        // exit on the probe alone, so this source costs the
        // dashboard exactly one query in the expired state.
        // The recording (Action Center page) read below walks
        // the prints to stamp ACTED / GAP-CLOSED / EXPIRED
        // correctly — an in-window act outranks expiry.
        if ($now->gt($windowEnd) && ! $record) {
            return null;
        }

        $prints = $this->printTimes($business);
        $proven = $this->provenPlacement($business, $settled['referrer_membership_id']);

        // The placed set AT settlement: prints up to the
        // settlement instant, plus the proven placement (a
        // referrer scanned it, so it is out in the world even
        // if its print record is missing).
        $baseline = [];
        foreach ($prints as $placement => $at) {
            if ($at->lte($settledAt)) {
                $baseline[] = $placement;
            }
        }
        if ($proven !== null && ! in_array($proven, $baseline, true)) {
            $baseline[] = $proven;
        }

        // L1: already satisfied at settlement — never renders.
        if (count($baseline) >= 2) {
            if ($record) {
                $this->stamp($business, $state, SecondPlacementPrompt::STATE_SATISFIED, $settled['referral_id'], $settledAt, null);
            }

            return null;
        }

        $offered = $this->offerFor($baseline, $proven);

        // L4/L5: a print of a NEW placement inside the window
        // is the terminal act — the suggested one reads ACTED,
        // any other reads GAP-CLOSED (organic; the feature's
        // job was the outcome, not the credit).
        $postPrints = [];
        foreach ($prints as $placement => $at) {
            if ($at->gt($settledAt) && $at->lte($windowEnd) && ! in_array($placement, $baseline, true)) {
                $postPrints[$placement] = $at;
            }
        }
        if ($postPrints !== []) {
            asort($postPrints);
            $actedPlacement = array_key_first($postPrints);
            if ($record) {
                $this->stamp(
                    $business,
                    $state,
                    $actedPlacement === $offered
                        ? SecondPlacementPrompt::STATE_ACTED
                        : SecondPlacementPrompt::STATE_GAP_CLOSED,
                    $settled['referral_id'],
                    $settledAt,
                    $offered,
                    $actedPlacement,
                    $postPrints[$actedPlacement],
                );
            }

            return null;
        }

        // L8: the window closed with no act. Expiry is
        // terminal even if the row was never shown.
        if ($now->gt($windowEnd)) {
            if ($record) {
                $this->stamp($business, $state, SecondPlacementPrompt::STATE_EXPIRED, $settled['referral_id'], $settledAt, $offered);
            }

            return null;
        }

        // L7: closed, or past-due past its grace (the flow
        // paused), stands the row down. The clock keeps
        // running (expiry above is measured from the
        // settlement instant regardless); reactivation inside
        // the window returns the row by this same read.
        if ($business->isClosed()
            || ($business->billing_status === 'past_due' && $business->dunningPausedAt())) {
            return null;
        }

        // L3: the row is live.
        if ($record) {
            $this->stamp($business, $state, SecondPlacementPrompt::STATE_LIVE, $settled['referral_id'], $settledAt, $offered);
            $this->recordImpression($business, $settled['referral_id'], $offered);
        }

        $names = $this->displayNames($business);

        $line = $proven !== null
            ? 'Your first visit that counts just came in, through your '.$names[$proven].'.'
            : 'Your first visit that counts just came in.';
        $line .= ' Put a code in one more spot so more customers can join. Try the '.$names[$offered].': '.self::SPOT_LINES[$offered];
        if ($offered === 'table') {
            $line .= ' If your customers sit at tables, this is the one they see while they wait.';
        }

        return [
            'key' => self::ITEM_KEY,
            'urgency' => 82,
            'line' => $line,
            'action_label' => self::PRINT_LABELS[$offered],
            'url' => route('owner.qr.poster', ['variant' => self::PLACEMENT_POSTER[$offered], 'print' => 1]),
            'fingerprint' => 'second_placement|'.$settled['referral_id'].'|'.$offered,
            'dismissible' => true,
            'dismiss_label' => 'Not now',
        ];
    }

    /**
     * Dismissal (Section 5.4): terminal, in addition to the
     * standard Action Center dismissal row ActionCenter
     * writes. The terminal stamp is the authority — a later
     * fingerprint change (a refunded trigger, a new settling
     * visit) must not resurface a row the owner refused.
     */
    public function stampDismissed(Business $business): void
    {
        $probe = $this->probe($business);
        $state = $probe['prompt'];

        if ($state !== null && $state->isTerminal()) {
            return;
        }

        $settled = $probe['settled'];
        $this->stamp(
            $business,
            $state,
            SecondPlacementPrompt::STATE_DISMISSED,
            $settled['referral_id'] ?? $state?->trigger_referral_id,
            $settled['settled_at'] ?? $state?->settled_at,
            $state?->offered_placement,
        );
    }

    // ---------- Internals ----------

    /**
     * ONE combined read: the prompt-state row, the first
     * settled visit, and the armed (held-first) candidate, in
     * a single query — the dashboard's query budget counts
     * this source at exactly one in every state where the row
     * does not render (the settled-but-expired and not-yet-
     * settled states included).
     *
     * @return array{prompt: ?SecondPlacementPrompt, settled: ?array{referral_id: int, referrer_membership_id: int, settled_at: Carbon}, armed: ?array{referral_id: int}}
     */
    private function probe(Business $business): array
    {
        $releaseInstants = DB::table('referral_events')
            ->selectRaw('referral_id, MIN(created_at) as released_at')
            ->where('event_type', 'review_release')
            ->whereNotNull('referral_id')
            ->groupBy('referral_id');

        $settledSub = DB::table('referrals')
            ->selectRaw(
                'referrals.id as referral_id, referrals.referrer_membership_id as referrer_membership_id, '
                .'CASE WHEN rel.released_at IS NOT NULL AND rel.released_at >= referrals.redeemed_at '
                .'THEN rel.released_at ELSE referrals.redeemed_at END as settled_at'
            )
            ->join('billing_events', 'billing_events.referral_id', '=', 'referrals.id')
            ->leftJoin('rewards', 'rewards.referral_id', '=', 'referrals.id')
            ->leftJoinSub($releaseInstants, 'rel', 'rel.referral_id', '=', 'referrals.id')
            ->where('referrals.business_id', $business->id)
            ->where('referrals.state', 'redeemed')
            ->whereNotNull('referrals.redeemed_at')
            ->whereNotIn('billing_events.coverage', ['held', 'fraud_denied', 'refunded'])
            ->where(function ($query) {
                $query->whereNull('rewards.id')
                    ->orWhere(function ($inner) {
                        $inner->where('rewards.hold', 0)
                            ->where('rewards.state', '!=', 'denied');
                    });
            })
            ->orderBy('settled_at')
            ->orderBy('referrals.id')
            ->limit(1);

        $armedSub = DB::table('referrals')
            ->selectRaw('referrals.id as referral_id')
            ->join('billing_events', 'billing_events.referral_id', '=', 'referrals.id')
            ->leftJoin('rewards', 'rewards.referral_id', '=', 'referrals.id')
            ->where('referrals.business_id', $business->id)
            ->where('referrals.state', 'redeemed')
            ->whereNotNull('referrals.redeemed_at')
            ->whereNotIn('billing_events.coverage', ['fraud_denied', 'refunded'])
            ->where(function ($query) {
                $query->where('billing_events.coverage', 'held')
                    ->orWhere('rewards.hold', 1);
            })
            ->where(function ($query) {
                $query->whereNull('rewards.id')
                    ->orWhere('rewards.state', '!=', 'denied');
            })
            ->orderBy('referrals.redeemed_at')
            ->orderBy('referrals.id')
            ->limit(1);

        $row = DB::table('businesses as b')
            ->select([
                'p.id as prompt_id',
                'p.trigger_referral_id as prompt_trigger_referral_id',
                'p.state as prompt_state',
                'p.settled_at as prompt_settled_at',
                'p.offered_placement as prompt_offered_placement',
                'p.acted_placement as prompt_acted_placement',
                'p.acted_at as prompt_acted_at',
                's.referral_id as settled_referral_id',
                's.referrer_membership_id as settled_referrer_membership_id',
                's.settled_at as settled_at',
                'a.referral_id as armed_referral_id',
            ])
            ->leftJoin('second_placement_prompts as p', 'p.business_id', '=', 'b.id')
            ->leftJoinSub($settledSub, 's', fn ($join) => $join->whereRaw('1 = 1'))
            ->leftJoinSub($armedSub, 'a', fn ($join) => $join->whereRaw('1 = 1'))
            ->where('b.id', $business->id)
            ->first();

        $prompt = null;
        if ($row !== null && $row->prompt_id !== null) {
            $prompt = new SecondPlacementPrompt;
            $prompt->forceFill([
                'id' => $row->prompt_id,
                'business_id' => $business->id,
                'trigger_referral_id' => $row->prompt_trigger_referral_id,
                'state' => $row->prompt_state,
                'settled_at' => $row->prompt_settled_at,
                'offered_placement' => $row->prompt_offered_placement,
                'acted_placement' => $row->prompt_acted_placement,
                'acted_at' => $row->prompt_acted_at,
            ]);
            $prompt->exists = true;
        }

        return [
            'prompt' => $prompt,
            'settled' => ($row !== null && $row->settled_referral_id !== null) ? [
                'referral_id' => (int) $row->settled_referral_id,
                'referrer_membership_id' => (int) $row->settled_referrer_membership_id,
                'settled_at' => Carbon::parse($row->settled_at),
            ] : null,
            'armed' => ($row !== null && $row->armed_referral_id !== null) ? [
                'referral_id' => (int) $row->armed_referral_id,
            ] : null,
        ];
    }

    /**
     * Write (or move) the prompt-state row. A terminal row is
     * never rewritten; a non-terminal row follows the live
     * derivation (a refunded trigger's stamp is replaced by
     * the next settling visit's detection, L6).
     */
    private function stamp(
        Business $business,
        ?SecondPlacementPrompt $current,
        string $state,
        ?int $triggerReferralId,
        ?Carbon $settledAt,
        ?string $offeredPlacement,
        ?string $actedPlacement = null,
        ?Carbon $actedAt = null,
    ): void {
        if ($current !== null && $current->isTerminal()) {
            return;
        }

        SecondPlacementPrompt::query()->updateOrCreate(
            ['business_id' => $business->id],
            [
                'trigger_referral_id' => $triggerReferralId,
                'state' => $state,
                'settled_at' => $settledAt,
                'offered_placement' => $offeredPlacement,
                'acted_placement' => $actedPlacement,
                'acted_at' => $actedAt,
            ],
        );
    }

    /** One impression per business per business-local day the row renders (Section 8.1 item 4). */
    private function recordImpression(Business $business, int $triggerReferralId, string $offeredPlacement): void
    {
        $shownOn = $business->localTime(now())->format('Y-m-d');

        // ID-04's impression pattern: an explicit existence
        // read (whereDate — the stored form carries a time
        // component) before the insert, never firstOrCreate.
        $exists = SecondPlacementPromptImpression::query()
            ->where('business_id', $business->id)
            ->whereDate('shown_on', $shownOn)
            ->exists();

        if (! $exists) {
            SecondPlacementPromptImpression::create([
                'business_id' => $business->id,
                'trigger_referral_id' => $triggerReferralId,
                'offered_placement' => $offeredPlacement,
                'shown_on' => $shownOn,
            ]);
        }
    }

    /**
     * Every placement's current display name in one read: the
     * active code's displayName() (the owner-set label, else
     * the format name), falling back to the format name for a
     * placement with no active code row yet.
     *
     * @return array<string, string>
     */
    private function displayNames(Business $business): array
    {
        $names = [];
        foreach (QrCode::PLACEMENTS as $placement) {
            $names[$placement] = QrCode::formatNameForPlacement($placement);
        }

        $codes = QrCode::query()
            ->where('business_id', $business->id)
            ->where('state', 'active')
            ->whereIn('placement', QrCode::PLACEMENTS)
            ->orderBy('id')
            ->get();

        foreach ($codes as $code) {
            $names[$code->placement] = $code->displayName();
        }

        return $names;
    }
}
