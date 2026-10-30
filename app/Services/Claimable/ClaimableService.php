<?php

namespace App\Services\Claimable;

use App\Models\Business;
use App\Models\ClaimablePage;
use App\Models\ClaimReview;
use App\Models\ClaimSuppression;
use App\Support\ClaimStarters;
use App\Support\OpaqueToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The claimable-page lifecycle (G1 design pass §5, §7, §8): generation
 * with its preconditions (suppression checked BEFORE any snapshot is
 * taken, H9 dedupe against existing customers, OPERATIONAL liveness
 * as an operator-attested input), the sent stamp that alone puts a
 * page in the §9.1 claim-rate denominator, the 60-day expiry purge,
 * and the full removal lifecycle R0-R4 with its restore check.
 *
 * The Google Places fetch itself is an operator-side act (the named
 * source, §1): this service receives the field-masked snapshot. The
 * concierge cohort (§11) hand-enters the same field set from a human
 * sighting. No generator exists in this build — §11's gate.
 */
class ClaimableService
{
    public const PLACES_SOURCE = 'google_places';
    public const CONCIERGE_SOURCE = 'operator_sighting';

    /**
     * Create a page from a §1 snapshot. Returns [page, plainToken]
     * or throws ClaimableGenerationBlocked with the guard reason
     * (suppressed / quiet / existing customer / not operational) —
     * every block is an H9 guard event, logged by the caller.
     *
     * @param  array<string, mixed>  $snapshot  displayName, formattedAddress parts,
     *                                          placesType, nationalPhoneNumber,
     *                                          openingHours, photoRef, businessStatus
     * @return array{0: ClaimablePage, 1: string}
     */
    public function generate(string $placeId, array $snapshot, string $cohort = 'concierge', string $source = self::CONCIERGE_SOURCE): array
    {
        // §8.5: suppression is checked BEFORE the snapshot is used,
        // so a suppressed shop produces no new snapshot to hold.
        if (ClaimSuppression::activeFor($placeId) !== null) {
            throw new ClaimableGenerationBlocked('suppressed');
        }

        // §8.2 R4 (review item 2b): a second removal leaves a
        // standing operator-review flag on the suppression row.
        // The flag outlives the suppression clock: even after
        // suppression_ends_at passes, generation stays blocked
        // until the named operator decision is recorded in the
        // register (recordOperatorReviewDecision()).
        $flagged = ClaimSuppression::query()
            ->where('place_id', $placeId)
            ->where('operator_review_flag', true)
            ->exists();
        if ($flagged) {
            throw new ClaimableGenerationBlocked('operator_review_flag');
        }

        if (DB::table('claim_quiet_list')->where('place_id', $placeId)->where('quiet_until', '>', now())->exists()) {
            throw new ClaimableGenerationBlocked('quiet_list');
        }

        if (($snapshot['businessStatus'] ?? 'OPERATIONAL') !== 'OPERATIONAL') {
            throw new ClaimableGenerationBlocked('not_operational');
        }

        // H9 dedupe: never generate for an existing customer —
        // listed-phone match, or name+city match.
        $phone = $snapshot['nationalPhoneNumber'] ?? null;
        $name = (string) ($snapshot['displayName'] ?? '');

        $dupe = Business::query()
            ->where(function ($q) use ($phone, $name, $snapshot) {
                if ($phone) {
                    $q->where('contact_phone_e164', $phone);
                }
                $q->orWhere(fn ($q2) => $q2->where('name', $name)->where('city', $snapshot['city'] ?? null));
            })
            ->exists();

        if ($dupe) {
            throw new ClaimableGenerationBlocked('existing_customer');
        }

        $mapped = ClaimStarters::mapPlacesType($snapshot['placesType'] ?? null);
        [$businessType, $tradeLabel] = $mapped ?? [null, 'shop'];
        $plain = OpaqueToken::generate();

        $page = ClaimablePage::create([
            'token_hash' => OpaqueToken::hash($plain),
            'place_id' => $placeId,
            'cohort' => $cohort,
            'shop_name' => $name,
            'business_type' => $businessType,
            'trade_label' => $tradeLabel,
            'street' => $snapshot['street'] ?? null,
            'city' => $snapshot['city'] ?? null,
            'state' => $snapshot['state'] ?? null,
            'postal_code' => $snapshot['postalCode'] ?? null,
            'listed_phone_e164' => $phone,
            'opening_hours' => $snapshot['openingHours'] ?? null,
            'photo_ref' => $snapshot['photoRef'] ?? null,
            'starter_reward_text' => ClaimStarters::starterFor($businessType),
            'snapshot_fetched_at' => now(),
            'status' => ClaimablePage::STATUS_GENERATED,
            'expires_at' => now()->addDays((int) config('claimable.page_lifetime_days')),
        ]);

        $page->recordEvent('generated', ['source' => $source]);

        return [$page, $plain];
    }

    public function findByToken(string $plain): ?ClaimablePage
    {
        return ClaimablePage::query()->where('token_hash', OpaqueToken::hash($plain))->first();
    }

    /**
     * §8.2 R4: record the named operator decision for a flagged
     * place_id. The act of recording IS the register entry: the
     * suppression row keeps its removal history (count, dates)
     * and the standing flag clears, so generation may proceed
     * (subject to every other guard). Nothing else clears it —
     * time alone never does.
     */
    public function recordOperatorReviewDecision(string $placeId): void
    {
        ClaimSuppression::query()
            ->where('place_id', $placeId)
            ->where('operator_review_flag', true)
            ->update(['operator_review_flag' => false]);
    }

    /** §5 P1: the sent stamp — recorded only on an actual (owner-gated) distribution. */
    public function markSent(ClaimablePage $page): ClaimablePage
    {
        if ($page->status === ClaimablePage::STATUS_GENERATED) {
            // §1 freshness: a snapshot older than 30 days must be
            // re-fetched before the page may be marked sent. The
            // re-fetch is the operator's act; the stamp refuses a
            // stale snapshot rather than sending stale facts.
            abort_if($page->snapshot_fetched_at === null
                || $page->snapshot_fetched_at->lessThan(now()->subDays((int) config('claimable.snapshot_fresh_days'))), 422, 'Snapshot is stale; re-fetch before sending.');

            $page->forceFill(['status' => ClaimablePage::STATUS_SENT, 'sent_at' => now()])->save();
            $page->recordEvent('sent');
        }

        return $page;
    }

    /**
     * §5 P7 / §9: expire a page whose 60 days ran out unclaimed.
     * Content and snapshot purge; the register row (place_id, name,
     * counts, timestamps) is retained. A second expiry for the same
     * place earns the 12-month operator quiet list (§7) — tracked
     * via the register's own expired rows.
     */
    public function expire(ClaimablePage $page): void
    {
        if (! $page->isOpenable() && $page->status !== ClaimablePage::STATUS_PENDING_REVIEW) {
            return;
        }

        $page->forceFill(['status' => ClaimablePage::STATUS_EXPIRED])->save();
        $page->recordEvent('expired');
        $page->purgeSnapshot();

        $priorExpiries = ClaimablePage::query()
            ->where('place_id', $page->place_id)
            ->where('status', ClaimablePage::STATUS_EXPIRED)
            ->count();

        if ($priorExpiries >= 2) {
            DB::table('claim_quiet_list')->updateOrInsert(
                ['place_id' => $page->place_id],
                ['reason' => 'two_expiries', 'quiet_until' => now()->addMonths((int) config('claimable.quiet_months')), 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    /** §7/§5 P9: killed by us (liveness failure). Same outward shape as expiry. */
    public function kill(ClaimablePage $page, string $reason): void
    {
        $page->forceFill(['status' => ClaimablePage::STATUS_KILLED, 'kill_reason' => $reason])->save();
        $page->recordEvent('killed', ['reason' => $reason]);
        $page->purgeSnapshot();
    }

    /**
     * §8.1/R0: the one-tap removal. Unverified by design; the page
     * hides at once for everyone, in-flight claims die (§8.4), and
     * the care (restore window, purge, suppression) is staged in
     * the days after. A page already CLAIMED never routes here —
     * §8.4's claimed branch is the caller's (cancellation or the
     * contested path), and suppression never applies to it.
     */
    public function requestRemoval(ClaimablePage $page, string $source): ClaimablePage
    {
        if ($page->status === ClaimablePage::STATUS_CLAIMED) {
            throw new ClaimableGenerationBlocked('already_claimed');
        }

        // §8.5 (review item 2a): expiry (P7) and a liveness kill
        // (P9) are NOT removals and never suppress. An ended page
        // can never enter the removal transition — the refusal is
        // here in the service as well as at the route, so no
        // caller can manufacture a suppression from a stale link.
        if (in_array($page->status, [ClaimablePage::STATUS_EXPIRED, ClaimablePage::STATUS_KILLED], true)) {
            throw new ClaimableGenerationBlocked('not_removable');
        }

        if ($page->status === ClaimablePage::STATUS_REMOVED) {
            return $page;
        }

        $inFlight = $page->status === ClaimablePage::STATUS_PENDING_REVIEW
            || $page->events()->whereIn('event', ['claim_started', 'gate_code_sent', 'gate_passed'])->exists();

        $page->forceFill([
            'status_at_removal' => $page->status,
            'claim_in_flight_at_removal' => $inFlight,
            'status' => ClaimablePage::STATUS_REMOVED,
            'removed_at' => now(),
            'removal_source' => $source,
        ])->save();

        // §8.4: an in-flight review is cancelled, never denied — no
        // identity lockout attaches to a removal.
        ClaimReview::query()
            ->where('claimable_page_id', $page->id)
            ->where('status', 'pending')
            ->update(['status' => 'closed_removed', 'decided_at' => now()]);

        $page->recordEvent('removed', ['source' => $source, 'prior_status' => $page->status_at_removal, 'claim_in_flight' => $inFlight]);

        return $page;
    }

    /**
     * §8.3's restore check outcome, applied by the operator after
     * the single call. $shopConfirms=true: the removal stands and
     * purge fires on confirmation. false (shop denies): a troll
     * attempt — the page is restored to its prior state with its
     * ORIGINAL expiry, and NO suppression is ever made.
     */
    public function resolveRestoreCheck(ClaimablePage $page, bool $shopConfirms): void
    {
        if ($shopConfirms) {
            $this->purgeAndSuppress($page);

            return;
        }

        // Review item 2c: the restore returns the page to its TRUE
        // prior state. A page removed while pending_review has no
        // status_at_removal of sent/generated to map back to, so
        // its prior sent state derives from the sent stamp itself
        // (the same derivation ClaimReviewService::deny() uses):
        // never-sent (no sent_at) returns to generated, never
        // labelled sent.
        $prior = $page->status_at_removal;
        $restored = match (true) {
            $prior === ClaimablePage::STATUS_GENERATED => ClaimablePage::STATUS_GENERATED,
            $prior === ClaimablePage::STATUS_SENT => ClaimablePage::STATUS_SENT,
            default => $page->sent_at !== null
                ? ClaimablePage::STATUS_SENT : ClaimablePage::STATUS_GENERATED,
        };

        $page->forceFill([
            'status' => $restored,
            'removed_at' => null, 'removal_source' => null,
            'status_at_removal' => null, 'claim_in_flight_at_removal' => false,
        ])->save();
        $page->recordEvent('restored', ['cause' => 'troll_removal_denied']);
    }

    /** R1: the shop itself says "we never asked" inside the window. */
    public function restoreAtShopRequest(ClaimablePage $page): void
    {
        abort_unless($page->inRestoreWindow(), 422, 'The restore window has closed.');

        $this->resolveRestoreCheck($page, false);
    }

    /**
     * R2/R3: purge the snapshot and write the suppression row
     * (12 months from the REMOVAL date). Runs at window close, on
     * shop confirmation, or when the §8.3 check ends unreachable.
     * A second removal restarts the clock and sets the standing
     * operator-review flag (§8.2 R4).
     */
    public function purgeAndSuppress(ClaimablePage $page): void
    {
        if ($page->purged_at !== null) {
            return;
        }

        $existing = ClaimSuppression::query()->where('place_id', $page->place_id)->first();
        $count = ($existing?->removal_count ?? 0) + 1;

        ClaimSuppression::updateOrCreate(
            ['place_id' => $page->place_id],
            [
                'shop_name' => $page->shop_name,
                'removed_at' => $page->removed_at,
                'suppression_ends_at' => $page->removed_at->copy()->addMonths((int) config('claimable.suppression_months')),
                'removal_count' => $count,
                'operator_review_flag' => $count >= 2,
            ],
        );

        $page->forceFill(['removal_count' => $count, 'operator_review_flag' => $count >= 2])->save();
        $page->purgeSnapshot();
    }

    /** The day-7 sweep: close restore windows that ended undisputed (§8.2 R2). */
    public function closeRestoreWindows(): int
    {
        $due = ClaimablePage::query()
            ->where('status', ClaimablePage::STATUS_REMOVED)
            ->whereNull('purged_at')
            ->where('removed_at', '<=', now()->subDays((int) config('claimable.restore_window_days')))
            ->get();

        foreach ($due as $page) {
            $this->purgeAndSuppress($page);
        }

        return $due->count();
    }

    /**
     * §4 / C32 (review item 1b): the pending-review lock is 7 days
     * (config review_lock_days), counted from the pending review
     * request. PICK, per codebase norms: derive-at-request-time is
     * primary — ClaimableController::page() calls expireReviewLock()
     * on every read, the same pattern by which an openable page
     * past its 60 days becomes P7 on read — and the hourly sweep
     * (expireReviewLocks()) frees locked pages nobody has read, so
     * the register is right even without a visitor. Either way the
     * freeing needs NO operator action: the pending review rows
     * close as `expired` and the page returns to its pre-lock
     * state (sent if it carries a sent stamp, else generated —
     * §5 P4: "Denial/expiry -> back to P1/P2 status").
     */
    public function expireReviewLock(ClaimablePage $page): bool
    {
        if ($page->status !== ClaimablePage::STATUS_PENDING_REVIEW) {
            return false;
        }

        $pending = $page->reviews()
            ->where('kind', ClaimReview::KIND_GATE_FALLBACK)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($pending === null || $pending->created_at === null
            || now()->lessThan($pending->created_at->copy()->addDays((int) config('claimable.review_lock_days')))) {
            return false;
        }

        return DB::transaction(function () use ($page) {
            $page->reviews()
                ->where('kind', ClaimReview::KIND_GATE_FALLBACK)
                ->where('status', 'pending')
                ->update(['status' => 'expired', 'decided_at' => now()]);

            $page->forceFill([
                'status' => $page->sent_at !== null
                    ? ClaimablePage::STATUS_SENT : ClaimablePage::STATUS_GENERATED,
            ])->save();

            return true;
        });
    }

    /** The sweep leg of the review-lock expiry: free every due page. */
    public function expireReviewLocks(): int
    {
        $freed = 0;

        ClaimablePage::query()
            ->where('status', ClaimablePage::STATUS_PENDING_REVIEW)
            ->get()
            ->each(function (ClaimablePage $page) use (&$freed) {
                $freed += $this->expireReviewLock($page) ? 1 : 0;
            });

        return $freed;
    }

    /** The expiry sweep for openable / pending pages past their 60 days. */
    public function expireDue(): int
    {
        $due = ClaimablePage::query()
            ->whereIn('status', [ClaimablePage::STATUS_GENERATED, ClaimablePage::STATUS_SENT, ClaimablePage::STATUS_PENDING_REVIEW])
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($due as $page) {
            $this->expire($page);
        }

        return $due->count();
    }

    /**
     * §9.1 claim rate for a cohort: CLAIMED / SENT, removed pages
     * staying in the denominator. Removed-per-sent rides beside it,
     * never netted against it.
     *
     * @return array{claimed: int, sent: int, removed: int, claim_rate: ?float, removed_rate: ?float}
     */
    public function claimRate(string $cohort): array
    {
        $base = ClaimablePage::query()->where('cohort', $cohort)->whereNotNull('sent_at');
        $sent = (clone $base)->count();
        $claimed = (clone $base)->where('status', ClaimablePage::STATUS_CLAIMED)->count();
        $removed = (clone $base)->whereNotNull('removed_at')->count();

        return [
            'claimed' => $claimed,
            'sent' => $sent,
            'removed' => $removed,
            'claim_rate' => $sent > 0 ? $claimed / $sent : null,
            'removed_rate' => $sent > 0 ? $removed / $sent : null,
        ];
    }
}

class ClaimableGenerationBlocked extends \RuntimeException {}
